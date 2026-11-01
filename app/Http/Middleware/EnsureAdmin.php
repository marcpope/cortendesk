<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Admin-only console sections (users, groups, settings, login history).
     * Non-admins are bounced to the overview rather than shown a raw 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_admin) {
            return redirect()
                ->route('overview')
                ->with('denied', __('messages.section_admin_only'));
        }

        return $next($request);
    }
}
