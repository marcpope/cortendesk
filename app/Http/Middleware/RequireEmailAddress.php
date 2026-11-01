<?php

namespace App\Http\Middleware;

use App\Services\OidcService;
use App\Support\LivewireRequest;
use App\Support\LoginEmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make a user set an email address when sign-in verification needs one.
 *
 * Same shape as RequireTwoFactor: a user who cannot satisfy an enabled control
 * is sent to the screen where they can fix it, and only that screen and logout
 * are reachable until they do.
 *
 * Without this, an account with no address silently skipped verification
 * (LoginEmailVerification::required() returns false when there is nothing to
 * send to) — so the control was enabled and simply did not apply to whoever
 * happened to have a blank email. Now it applies to everyone or nobody.
 *
 * SSO sessions are exempt for the same reason they are exempt from 2FA: the
 * identity provider authenticated them, and a provisioned account's address is
 * the provider's to set, not the user's.
 */
class RequireEmailAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->session()->get(OidcService::SESSION_PROVIDER)) {
            return $next($request);
        }

        if ($user
            && trim((string) $user->email) === ''
            && LoginEmailVerification::isActive()
            && ! $this->isAllowed($request)) {
            return redirect()->route('account')->with(
                'email_required',
                __('messages.email_required'),
            );
        }

        return $next($request);
    }

    /** Screens a user without an address may still reach, so they can fix it. */
    private function isAllowed(Request $request): bool
    {
        if (LivewireRequest::isComponentUpdate($request)) {
            return true;
        }

        return $request->routeIs('account', 'account.two-factor', 'logout');
    }
}
