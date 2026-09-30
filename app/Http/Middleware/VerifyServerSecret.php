<?php

namespace App\Http\Middleware;

use App\Services\ServerLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the endpoints CortenDesk Server calls (docs/server-link.md):
 * bearer token equal to CORTENDESK_SERVER_SECRET. 404 while no secret is set,
 * so the endpoints do not exist on an unlinked console.
 */
class VerifyServerSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ServerLink::configured()) {
            return response()->json(['error' => 'Server link is not configured.'], 404);
        }

        if (! hash_equals(ServerLink::secret(), (string) $request->bearerToken())) {
            return response()->json(['error' => 'Invalid server secret.'], 401);
        }

        return $next($request);
    }
}
