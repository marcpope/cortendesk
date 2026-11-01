<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce a delegated-role permission on a console route (PLAN D4).
 *
 * Usage:  ->middleware('console-can:user,rw')
 *
 * Mirrors `api-token-can` for the browser side. It gates *mounting* a screen;
 * the read-versus-write split inside a screen is enforced by the Livewire
 * components themselves (App\Livewire\Concerns\AuthorizesConsole), because one
 * page can host both.
 *
 * Denial redirects to the overview with a flash rather than rendering a raw
 * 403, preserving the bounce UX EnsureAdmin has always given non-admins.
 */
class ConsoleCan
{
    public function handle(Request $request, Closure $next, string $resource, string $level = 'r'): Response
    {
        $user = $request->user();

        if (! $user || ! $user->consoleAllows($resource, $level)) {
            return redirect()
                ->route('overview')
                ->with('denied', __('messages.section_restricted'));
        }

        return $next($request);
    }
}
