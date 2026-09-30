<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With SESSION_SECURE_COOKIE unset, the session and XSRF cookies carry the
 * Secure flag exactly when the request is HTTPS (directly or via a trusted
 * proxy). One console then works over HTTPS and over plain HTTP on the LAN.
 * An explicit true or false still wins (#78).
 *
 * Runs in the web group, after TrustProxies has applied X-Forwarded-Proto
 * and before StartSession writes the cookie.
 */
class SessionCookieFollowsScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('session.secure') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        return $next($request);
    }

    /** Sign-in cannot work: cookies are HTTPS-only and this request is HTTP. */
    public static function blocksSignIn(Request $request): bool
    {
        return config('session.secure') === true && ! $request->isSecure();
    }
}
