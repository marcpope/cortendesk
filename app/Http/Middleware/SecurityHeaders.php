<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every response PHP sends. nginx adds
 * the first two to static files (docker/nginx.conf.template). A header the
 * response already carries is kept: share pages send X-Frame-Options: DENY.
 */
class SecurityHeaders
{
    /** Browser features no page uses. The web client needs the microphone, the clipboard and fullscreen. */
    public const PERMISSIONS_POLICY = 'accelerometer=(), bluetooth=(), camera=(), geolocation=(), gyroscope=(), hid=(), magnetometer=(), midi=(), payment=(), serial=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
        ];

        // Another site cannot put the console in a frame (clickjacking).
        if (! config('cortendesk.allow_framing')) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
