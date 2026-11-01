<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kick disabled accounts out of live console sessions. Login already refuses
 * inactive users; this closes the rest — sessions that were open when the
 * account was disabled, and remember-me cookie re-authentication.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => __('messages.account_disabled'),
            ]);
        }

        return $next($request);
    }
}
