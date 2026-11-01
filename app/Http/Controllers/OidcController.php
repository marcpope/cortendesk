<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Services\OidcException;
use App\Services\OidcService;
use App\Services\OidcUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Console single sign-on endpoints (PLAN D3).
 *
 * Both actions sit behind the `guest` middleware: an already-signed-in user has
 * no business starting an authorization request.
 */
class OidcController extends Controller
{
    public function __construct(
        private readonly OidcService $oidc,
        private readonly OidcUserResolver $resolver,
    ) {}

    /** Kick off the authorization-code flow. */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oidc->isEnabled()) {
            return redirect()->route('login')
                ->withErrors(['username' => __('messages.sso_not_enabled')]);
        }

        try {
            $url = $this->oidc->authorizationUrl($request, $this->callbackUrl());
        } catch (OidcException $e) {
            Log::warning('OIDC: could not start sign-in', ['error' => $e->getMessage()]);

            return redirect()->route('login')->withErrors(['username' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    /** Handle the provider's redirect back. */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->oidc->isEnabled()) {
            return redirect()->route('login')
                ->withErrors(['username' => __('messages.sso_not_enabled')]);
        }

        try {
            $result = $this->oidc->exchange($request, $this->callbackUrl());
        } catch (OidcException $e) {
            Log::warning('OIDC: sign-in failed', ['error' => $e->getMessage()]);

            $this->logFailure($request, null);

            return redirect()->route('login')->withErrors(['username' => $e->getMessage()]);
        }

        $claims = $result['claims'];
        $outcome = $this->resolver->resolve($claims);

        if ($outcome['status'] !== 'ok' || ! $outcome['user']) {
            $this->logFailure($request, (string) ($claims['preferred_username'] ?? $claims['email'] ?? 'sso'));

            return redirect()->route('login')->withErrors(['username' => $outcome['message']]);
        }

        $user = $outcome['user'];

        Auth::login($user);
        $request->session()->regenerate();

        // Mark the session as IdP-authenticated. RequireTwoFactor reads this to
        // exempt SSO sessions — the second factor is the IdP's responsibility —
        // and logout reads the ID token to end the session at the provider.
        $request->session()->put(OidcService::SESSION_PROVIDER, true);
        $request->session()->put(OidcService::SESSION_ID_TOKEN, $result['id_token']);

        LoginLog::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'client' => 'sso',
            'ip' => $request->ip(),
            'successful' => true,
        ]);

        return redirect()->intended(route('overview'));
    }

    /** Record a failed SSO attempt so it shows up alongside password failures. */
    private function logFailure(Request $request, ?string $username): void
    {
        LoginLog::create([
            'user_id' => null,
            'username' => $username ?: 'sso',
            'client' => 'sso',
            'ip' => $request->ip(),
            'successful' => false,
        ]);
    }

    /**
     * The redirect URI registered at the provider. Built from the request so it
     * follows the host the console is actually served on (X-Forwarded-* headers
     * are already trusted app-wide), which keeps reverse-proxy setups working.
     */
    private function callbackUrl(): string
    {
        return route('login.oidc.callback');
    }
}
