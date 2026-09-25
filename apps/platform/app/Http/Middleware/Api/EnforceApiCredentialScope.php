<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use App\Support\Api\ApiScope;
use App\Support\Api\ApiScopeInsufficientException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.3 (ADR 0049 sections 2 and 6): a HUMAN API token's scope, on
 * every `/api` request it authenticates -- `api.read` for safe methods,
 * `api.write` for everything else, no implication either way. Runs after
 * authentication and throttling (appended after ThrottleRequests in the
 * priority list), like a capability check. A scope never grants anything:
 * the route's own capability and School checks still apply. Partner
 * principals are checked per route by RequirePartnerScope instead.
 */
class EnforceApiCredentialScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /** @var mixed $token null, a TransientToken (session) or a PersonalAccessToken (bearer) */
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $abilities = (array) $token->abilities;

            if (! in_array(ApiScope::forMethod($request->method()), $abilities, true)) {
                throw new ApiScopeInsufficientException;
            }
        }

        return $next($request);
    }
}
