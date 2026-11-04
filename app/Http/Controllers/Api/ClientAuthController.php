<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SerializesUsers;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactor;
use App\Mail\LoginVerificationCode;
use App\Models\AlarmLog;
use App\Models\ClientToken;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\AppriseNotifications;
use App\Services\DeviceEnrollment;
use App\Services\MailSettings;
use App\Services\OidcService;
use App\Support\LoginEmailVerification;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
{
    use SerializesUsers;

    /**
     * Provider name advertised to the client and echoed back as `op`.
     * The console has a single OIDC provider, so this is a fixed handle.
     */
    public const CLIENT_PROVIDER = 'sso';

    /**
     * GET /api/login-options — the third-party sign-in buttons the client shows.
     *
     * This response IS the client's SSO UI: the stock app renders a button per
     * entry, so enabling SSO here makes one appear with no client change. The
     * `common-oidc/<json>` form carries a display name for the button; we send
     * a single entry because the console has one provider (spec §2).
     *
     * Must also answer HEAD — the client uses this URL as its TLS probe.
     */
    public function loginOptions(): JsonResponse
    {
        $oidc = app(OidcService::class);

        if (! $oidc->isEnabled()) {
            return response()->json([]);
        }

        // The client renders "Continue with {name}", so `name` carries the
        // display wording — there is no separate label field it reads.
        return response()->json([
            'common-oidc/'.json_encode([['name' => $oidc->clientProviderName()]]),
        ]);
    }

    /** How long a code prompt stays answerable, in seconds. */
    private const CHALLENGE_TTL = 600;

    /** Wrong codes one prompt takes before the password step must be redone. */
    private const CHALLENGE_TRIES = 5;

    /** Code attempts per account and address per minute, as on the console. */
    private const MAX_PER_ACCOUNT = 5;

    private const DECAY = 60;

    /** Don't re-raise the same alarm more often than this, in seconds. */
    private const ALARM_COOLDOWN = 300;

    /**
     * POST /api/login — spec §3.
     *
     * The console's second factor applies here too: TOTP when the account has
     * it, otherwise the emailed code when sign-in verification is on. The
     * password step then answers `email_check` with a secret, and the client
     * posts the code back with it as `type: email_code`.
     */
    public function login(Request $request): JsonResponse
    {
        // Force-SSO covers the client too, or a password the IdP no longer
        // vouches for (an imported account, say) skips its MFA (issue #102).
        // Same break-glass as the console form: off while SSO is unconfigured.
        if (app(OidcService::class)->localLoginDisabled()) {
            return response()->json(['error' => 'Password sign-in is disabled. Use single sign-on.']);
        }

        if (in_array($request->input('type'), ['email_code', 'tfa_code'], true)) {
            return $this->verifyCode($request);
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $deviceInfo = (array) $request->input('deviceInfo', []);

        $user = User::where('username', $username)->first();
        $ok = $user !== null
            && $user->is_active
            && $password !== ''
            && Hash::check($password, $user->password);

        $this->log($request, $username, $ok ? $user : null, $ok);

        if (! $ok) {
            // Same reasoning as the console form: unauthenticated, repeatable,
            // and delivery is a synchronous HTTP call, so it happens after the
            // response and its cooldown keys on the address alone.
            app(AppriseNotifications::class)->sendAfterResponse(
                'console.login_failed',
                'Failed console login',
                'A RustDesk client sign-in attempt failed.',
                'client-login:'.sha1((string) $request->ip()),
            );

            // The client shows this message verbatim (after i18n lookup).
            return response()->json(['error' => 'Wrong credentials']);
        }

        // The console form's refusals, in the same order.
        if ($user->isSsoProvisioned()) {
            return response()->json(['error' => __('messages.sso_only_account')]);
        }
        if ($user->isSsoPending()) {
            return response()->json(['error' => __('messages.awaiting_approval')]);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->challenge($request, $user, 'tfa');
        }

        // The console lets an un-enrolled user in only as far as the setup
        // screen. The client has no such screen, so it waits until they enrol.
        if (RequireTwoFactor::isRequiredFor($user)) {
            return response()->json(['error' => 'Two-factor authentication is required. Set it up in the web console, then sign in again.']);
        }

        // No trusted-device skip: the client has no cookie to carry one, and
        // it signs in rarely, since its token lasts.
        if (LoginEmailVerification::isActive() && trim((string) $user->email) !== '') {
            return $this->challenge($request, $user, 'email');
        }

        return $this->signIn($request, $user);
    }

    /**
     * Answer the password step with a code prompt. `tfa_type` picks the
     * client's dialog; `secret` comes back with the code.
     */
    private function challenge(Request $request, User $user, string $kind): JsonResponse
    {
        $pending = [
            'user_id' => $user->id,
            'kind' => $kind,
            'device_id' => (string) $request->input('id', ''),
            'expires' => now()->timestamp + self::CHALLENGE_TTL,
            'tries' => 0,
        ];

        if ($kind === 'email') {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sent = app(MailSettings::class)->send(
                new LoginVerificationCode($code, $user->username, (string) $request->ip(), intdiv(self::CHALLENGE_TTL, 60)),
                (string) $user->email,
            );

            // Fails closed for everyone. The console lets an administrator in
            // to repair the relay; the client cannot repair anything.
            if (! $sent) {
                return response()->json(['error' => __('messages.signin_unavailable_mail')]);
            }

            $pending['code_hash'] = Hash::make($code);
        }

        $secret = Str::random(40);
        Cache::put(self::challengeKey($secret), $pending, self::CHALLENGE_TTL);

        return response()->json([
            'type' => 'email_check',
            'tfa_type' => $kind === 'tfa' ? 'tfa_check' : 'email_check',
            'secret' => $secret,
            // The client echoes `name` back and shows `email` above the field.
            'user' => array_filter([
                'name' => $user->username,
                'email' => $kind === 'email' ? LoginEmailVerification::maskEmail((string) $user->email) : null,
            ]),
        ]);
    }

    /** Step 2: the code from the prompt, with the secret step 1 handed out. */
    private function verifyCode(Request $request): JsonResponse
    {
        $expired = response()->json(['error' => 'Your sign-in expired. Sign in again.']);

        $secret = (string) $request->input('secret', '');
        $key = self::challengeKey($secret);
        $pending = $secret !== '' ? Cache::get($key) : null;
        $user = is_array($pending) ? User::find($pending['user_id']) : null;

        // Bound to the device that gave the password.
        if (! $user || ! $user->is_active || $pending['device_id'] !== (string) $request->input('id', '')) {
            return $expired;
        }

        // Someone grinding codes already holds a valid password, so this
        // alarms, as the console's code steps do.
        $throttleKey = 'client-code:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_PER_ACCOUNT)) {
            if (Cache::add('alarm-raised:'.sha1($throttleKey), true, self::ALARM_COOLDOWN)) {
                AlarmLog::console(AlarmLog::TYP_BRUTE_FORCE, [
                    'ip' => $request->ip(),
                    'username' => $user->username,
                    'step' => $pending['kind'] === 'tfa' ? 'client-two-factor' : 'client-email-verification',
                    'attempts' => self::MAX_PER_ACCOUNT,
                ]);
            }

            return response()->json(['error' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.']);
        }

        // One code budget per account across the console and the client,
        // whatever the address (AuthController::MAX_CODES_PER_USER).
        $userKey = AuthController::codeUserKey($user);
        if (RateLimiter::tooManyAttempts($userKey, AuthController::MAX_CODES_PER_USER)) {
            return response()->json(['error' => 'Too many attempts. Try again in '.RateLimiter::availableIn($userKey).' seconds.']);
        }

        // Flutter sends the TOTP in both fields, the email code in
        // verificationCode only; Sciter sends an empty tfaCode.
        $code = preg_replace('/\D/', '', (string) ($request->input('tfaCode') ?: $request->input('verificationCode', ''))) ?? '';

        $error = match ($pending['kind']) {
            'tfa' => $user->hasTwoFactorEnabled() ? TwoFactor::consumeTotp($user, $code) : 'Your sign-in expired. Sign in again.',
            default => Hash::check($code, (string) ($pending['code_hash'] ?? '')) ? null : __('messages.code_incorrect_or_expired'),
        };

        if ($error !== null) {
            RateLimiter::hit($throttleKey, self::DECAY);
            RateLimiter::hit($userKey, AuthController::CODE_DECAY);
            $this->log($request, $user->username, null, false, 'wrong verification code');

            // A prompt takes a few wrong codes in total, whatever the rate.
            $pending['tries']++;
            $left = $pending['expires'] - now()->timestamp;
            $pending['tries'] >= self::CHALLENGE_TRIES || $left <= 0
                ? Cache::forget($key)
                : Cache::put($key, $pending, $left);

            return response()->json(['error' => $error]);
        }

        // One prompt, one token.
        if (Cache::pull($key) === null) {
            return $expired;
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($userKey);

        return $this->signIn($request, $user);
    }

    /** Issue the token and answer with AuthBody. */
    private function signIn(Request $request, User $user): JsonResponse
    {
        $deviceInfo = (array) $request->input('deviceInfo', []);

        $token = ClientToken::issue($user, [
            'id' => $request->input('id'),
            'uuid' => $request->input('uuid'),
            'os' => $deviceInfo['os'] ?? null,
            'name' => $deviceInfo['name'] ?? null,
            'type' => $deviceInfo['type'] ?? null,
        ]);

        // Registers a device the console has not seen (phones never send
        // sysinfo, issue #110), and approves a pending one when the setting
        // says so, making this user its owner (issue #95).
        DeviceEnrollment::signedIn($user, [
            'id' => (string) $request->input('id', ''),
            'uuid' => (string) $request->input('uuid', ''),
            'os' => $deviceInfo['os'] ?? null,
        ], $request->ip());

        // AuthBody: access_token, type and user are required by the client.
        return response()->json([
            'access_token' => $token->token,
            'type' => 'access_token',
            'tfa_type' => '',
            'secret' => '',
            'user' => $this->userPayload($user),
        ]);
    }

    private function log(Request $request, string $username, ?User $user, bool $ok, ?string $note = null): void
    {
        $deviceInfo = (array) $request->input('deviceInfo', []);

        LoginLog::create([
            'user_id' => $user?->id,
            'username' => $username,
            'client' => $deviceInfo['type'] ?? 'client',
            'device_id' => $request->input('id'),
            'device_os' => $deviceInfo['os'] ?? null,
            'ip' => $request->ip(),
            'successful' => $ok,
            'note' => $note,
        ]);
    }

    /** Only a hash of the secret is stored, so a cache read can't answer a prompt. */
    private static function challengeKey(string $secret): string
    {
        return 'client-login-challenge:'.hash('sha256', $secret);
    }

    /** POST /api/logout — spec §4. Failures are ignored client-side. */
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('client_token')?->delete();

        return response()->json((object) []);
    }

    /** POST /api/currentUser — spec §5: UserPayload at top level. */
    public function currentUser(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }
}
