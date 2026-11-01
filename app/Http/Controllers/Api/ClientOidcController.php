<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SerializesUsers;
use App\Http\Controllers\Controller;
use App\Models\ClientToken;
use App\Models\LoginLog;
use App\Models\OidcAuthRequest;
use App\Services\DeviceEnrollment;
use App\Services\OidcException;
use App\Services\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SSO for the RustDesk client itself (PLAN D3, client half; spec §6–7).
 *
 * The stock client already speaks this — no client modification, no custom
 * build. It calls POST /api/oidc/auth, opens the returned URL in the system
 * browser, and polls GET /api/oidc/auth-query until a token comes back.
 *
 * Two contract details from docs/client-api.md that are easy to get wrong and
 * fatal when wrong:
 *
 *  - The Rust paths IGNORE the HTTP status and read only the body, so every
 *    outcome here is a 200 with a JSON body. Errors are signalled by an
 *    `error` string.
 *  - While the authorization is pending the body must be exactly
 *    `{"error": "No authed oidc is found"}`. That substring is what makes the
 *    client keep polling; any other string aborts the flow and is shown to
 *    the user.
 */
class ClientOidcController extends Controller
{
    use SerializesUsers;

    /** The client's "keep polling" sentinel — must match verbatim. */
    private const PENDING = 'No authed oidc is found';

    public function __construct(private readonly OidcService $oidc) {}

    /** POST /api/oidc/auth — spec §6. Start the flow, hand back a code + URL. */
    public function auth(Request $request): JsonResponse
    {
        if (! $this->oidc->isEnabled()) {
            return response()->json(['error' => 'Single sign-on is not enabled on this server.']);
        }

        $deviceInfo = (array) $request->input('deviceInfo', []);

        $pending = OidcAuthRequest::start([
            'id' => $request->input('id'),
            'uuid' => $request->input('uuid'),
            'os' => $deviceInfo['os'] ?? null,
            'name' => $deviceInfo['name'] ?? null,
            'type' => $deviceInfo['type'] ?? null,
        ], (string) $request->input('op') ?: null);

        try {
            $url = $this->oidc->buildAuthorizationUrl(
                route('login.oidc.client-callback'),
                $pending->state,
                $pending->nonce,
                $pending->verifier,
            );
        } catch (OidcException $e) {
            $pending->delete();
            Log::warning('OIDC: client sign-in could not start', ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()]);
        }

        // `url` must be absolute — the client parses it with url::Url.
        return response()->json(['code' => $pending->code, 'url' => $url]);
    }

    /** GET /api/oidc/auth-query — spec §7. Polled once a second for 180s. */
    public function authQuery(Request $request): JsonResponse
    {
        $pending = OidcAuthRequest::query()
            ->where('code', (string) $request->query('code', ''))
            ->first();

        // An unknown or expired code reads as "still pending" rather than a
        // hard error: a hard error would abort a client that is merely slow,
        // and it avoids confirming which codes exist.
        if (! $pending || $pending->isExpired()) {
            return $this->pending();
        }

        if (! $pending->belongsToDevice($request->query('id'), $request->query('uuid'))) {
            return $this->pending();
        }

        if ($pending->failure) {
            $message = $pending->failure;
            $pending->delete();

            return response()->json(['error' => $message]);
        }

        if (! $pending->isAuthorized()) {
            return $this->pending();
        }

        $user = $pending->user;

        if (! $user) {
            $pending->delete();

            return response()->json(['error' => 'That account is no longer available.']);
        }

        $token = $pending->access_token;

        // One-shot: the code cannot be redeemed twice.
        $pending->delete();

        // AuthBody. `type` must be access_token for the client to persist it,
        // and user.info must be present or Rust deserialization fails and the
        // client silently hangs until its timeout.
        return response()->json([
            'access_token' => $token,
            'type' => 'access_token',
            'tfa_type' => '',
            'secret' => '',
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Complete the browser half: called by the IdP redirect, not by the app.
     *
     * Runs in the browser the client opened, so there is no console session
     * involved — everything needed was persisted when the flow started.
     */
    public function browserCallback(Request $request, \App\Services\OidcUserResolver $resolver)
    {
        $pending = OidcAuthRequest::query()
            ->where('state', (string) $request->query('state', ''))
            ->first();

        if (! $pending || $pending->isExpired()) {
            return response()->view('auth.oidc-client-result', [
                'ok' => false,
                'message' => __('messages.oidc_request_expired'),
            ], 400);
        }

        if ($request->query('error')) {
            return $this->fail($pending, __('messages.idp_rejected'));
        }

        try {
            $result = $this->oidc->exchangeCode(
                (string) $request->query('code', ''),
                route('login.oidc.client-callback'),
                $pending->verifier,
                $pending->nonce,
            );
        } catch (OidcException $e) {
            Log::warning('OIDC: client callback failed', ['error' => $e->getMessage()]);

            return $this->fail($pending, $e->getMessage());
        }

        $outcome = $resolver->resolve($result['claims']);

        if ($outcome['status'] !== 'ok' || ! $outcome['user']) {
            $this->logAttempt($pending, null, false);

            return $this->fail($pending, $outcome['message']);
        }

        $user = $outcome['user'];

        $token = ClientToken::issue($user, [
            'id' => $pending->device_id,
            'uuid' => $pending->device_uuid,
            'os' => $pending->device_os,
            'name' => $pending->device_name,
            'type' => $pending->client_type,
        ]);

        // Same as a password sign-in: register or approve the device (#95, #110).
        DeviceEnrollment::signedIn($user, [
            'id' => $pending->device_id,
            'uuid' => $pending->device_uuid,
            'os' => $pending->device_os,
        ]);

        $pending->forceFill([
            'user_id' => $user->id,
            'access_token' => $token->token,
            'authorized_at' => now(),
        ])->save();

        $this->logAttempt($pending, $user->username, true);

        return response()->view('auth.oidc-client-result', [
            'ok' => true,
            'message' => __('messages.signed_in_as', ['name' => $user->displayName()]),
        ]);
    }

    /** Record the failure for the poller, then show it in the browser. */
    private function fail(OidcAuthRequest $pending, string $message)
    {
        $pending->forceFill(['failure' => $message])->save();

        return response()->view('auth.oidc-client-result', [
            'ok' => false,
            'message' => $message,
        ], 403);
    }

    private function logAttempt(OidcAuthRequest $pending, ?string $username, bool $ok): void
    {
        LoginLog::create([
            'user_id' => $ok ? $pending->user_id : null,
            'username' => $username ?: 'sso',
            'client' => $pending->client_type ?: 'client',
            'device_id' => $pending->device_id,
            'device_os' => $pending->device_os,
            'ip' => request()->ip(),
            'successful' => $ok,
        ]);
    }

    private function pending(): JsonResponse
    {
        return response()->json(['error' => self::PENDING]);
    }
}
