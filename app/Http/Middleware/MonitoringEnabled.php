<?php

namespace App\Http\Middleware;

use App\Support\Monitoring;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 for every Monitoring screen and the agent API while Monitoring is off,
 * the same answer as a path that doesn't exist.
 */
class MonitoringEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Monitoring::enabled(), 404);

        return $next($request);
    }
}
