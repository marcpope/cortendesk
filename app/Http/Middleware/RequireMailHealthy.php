<?php

namespace App\Http\Middleware;

use App\Services\MailSettings;
use App\Support\LivewireRequest;
use App\Support\LoginEmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Walk an administrator to the mail settings while the relay is down.
 *
 * Sign-in verification closes the console to ordinary users when a code cannot
 * be sent (AuthController), so the only people who can still get in are those
 * able to repair it. This makes sure they land on the screen that repairs it,
 * rather than wandering the console while everyone else is locked out.
 *
 * Released as soon as a send succeeds — the "Send test email" button on that
 * screen is the intended way to prove it.
 */
class RequireMailHealthy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->session()->get('mail_repair')) {
            return $next($request);
        }

        $mail = app(MailSettings::class);

        // Fixed: drop the marker and let them get on with their day.
        if (! LoginEmailVerification::isActive() || $mail->isHealthy()) {
            $request->session()->forget('mail_repair');

            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        return redirect()->route('settings', ['tab' => 'email'])->with(
            'mail_broken',
            __('messages.mail_broken'),
        );
    }

    /** Where they may go: the settings screen, their own account, and out. */
    private function isAllowed(Request $request): bool
    {
        if (LivewireRequest::isComponentUpdate($request)) {
            return true;
        }

        return $request->routeIs('settings', 'account', 'account.two-factor', 'logout');
    }
}
