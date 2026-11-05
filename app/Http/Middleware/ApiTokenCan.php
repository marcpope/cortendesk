<?php

namespace App\Http\Middleware;

use App\Models\AddressBook;
use App\Models\ApiToken;
use App\Support\AuditLogStream;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce a per-resource permission on an admin-api route.
 *
 * Usage:  ->middleware('api-token-can:device,rw')
 *         ->middleware('api-token-can:address_book,rw,add')
 *
 * With "add", a token without the level still passes when its add-only grant
 * (#151) names the route's {addressBook}. The controller then sees
 * api_token_add_only and answers with as little as it can.
 *
 * Runs after the `auth:api-token` guard, which resolves the token and stashes
 * it on the request. Denies with the {code,data,message} envelope so the Pro
 * Python CLIs see a structured error rather than an HTML page.
 */
class ApiTokenCan
{
    public function handle(Request $request, Closure $next, string $resource, string $level = 'r', string $also = ''): Response
    {
        // Resolve the token from the bearer directly rather than from a request
        // attribute: the auth guard caches its resolved user across requests
        // within a single test run, so a side-effect attribute it sets is not
        // reliably present. This lookup is self-contained and always correct.
        $token = ApiToken::findValid((string) $request->bearerToken());

        // Owner must exist, be active, and still hold "manage API tokens" (see
        // the guard in AppServiceProvider — admin-API tokens follow the owner's
        // standing). consoleAllows short-circuits true for is_admin, so this is
        // unchanged for every token issued before delegated roles existed.
        if (! $token || ! $token->user || ! $token->user->is_active
            || ! $token->user->consoleAllows('token', 'rw')) {
            AuditLogStream::apiToken($request, $token, $resource, $level, 401);

            return response()->json([
                'code' => 401,
                'data' => null,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $addOnly = false;
        if (! $token->allows($resource, $level) && $also === 'add') {
            $book = $request->route('addressBook');
            $addOnly = $token->mayAddTo((int) ($book instanceof AddressBook ? $book->getKey() : $book));
        }

        if (! $addOnly && ! $token->allows($resource, $level)) {
            AuditLogStream::apiToken($request, $token, $resource, $level, 403);

            return response()->json([
                'code' => 403,
                'data' => null,
                'message' => "Token lacks '{$level}' permission on '{$resource}'.",
            ], 403);
        }

        // Stamp usage and expose the token to controllers (AdminApiController::token()).
        $token->markUsed();
        AuditLogStream::apiToken($request, $token, $resource, $level);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_token_add_only', $addOnly);

        return $next($request);
    }
}
