<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for the agent API: "<agent_id>.<secret>". An unknown or deleted
 * agent gets 401 `revoked`, which tells the agent to stop reporting.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = Agent::fromBearer($request->bearerToken());

        if (! $agent) {
            return response()->json(['error' => 'revoked'], 401);
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
