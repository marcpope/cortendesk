<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use App\Services\OidcService;
use App\Support\LivewireRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce 2FA enrollment (PLAN B6). When the console requires 2FA — globally
 * (`two_factor_required`) or for admins only (`two_factor_required_admins`) —
 * a signed-in user who hasn't enrolled is redirected to the setup screen.
 * Only the setup screen, its Livewire round-trips, and logout are allowed
 * through so the user can actually enroll (or bail out).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // SSO sessions are exempt (PLAN D3): the identity provider performed
        // the authentication, including whatever second factor it enforces, so
        // demanding a second enrollment here would be redundant and would block
        // provisioned accounts that have no password to protect in the first
        // place.
        if ($request->session()->get(OidcService::SESSION_PROVIDER)) {
            return $next($request);
        }

        if ($user && ! $user->hasTwoFactorEnabled() && self::isRequiredFor($user) && ! $this->isAllowed($request)) {
            return redirect()->route('account.two-factor')->with(
                'twofactor_enforced',
                __('messages.twofactor_required_setup'),
            );
        }

        return $next($request);
    }

    /** Is 2FA mandatory for this user given the current settings? */
    public static function isRequiredFor(User $user): bool
    {
        if (Setting::get('two_factor_required', '0') === '1') {
            return true;
        }

        if ($user->is_admin && Setting::get('two_factor_required_admins', '0') === '1') {
            return true;
        }

        // Per-role enforcement (PLAN B6 deferred this to D4's roles table).
        return (bool) $user->role?->require_two_factor;
    }

    /** Routes an un-enrolled user may still reach so they can enroll/leave. */
    private function isAllowed(Request $request): bool
    {
        // Livewire component round-trips (the setup wizard talks over these).
        if (LivewireRequest::isComponentUpdate($request)) {
            return true;
        }

        return $request->routeIs('account.two-factor', 'logout');
    }
}
