<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiClient;
use App\Support\Api\ApiScopeInsufficientException;
use App\Support\Api\PartnerScopeRegistry;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.3 (ADR 0049 section 6): `partner-scope:<scope>` -- a partner
 * route's registered scope, which the client must hold AND which must be
 * in the closed catalog available here (PartnerScopeRegistry). A stored
 * scope that is not available fails closed.
 */
class RequirePartnerScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = ApiClient::fromRequest($request);

        if ($client === null) {
            throw new AuthenticationException;
        }

        if (! PartnerScopeRegistry::isAvailable($scope) || ! $client->hasScope($scope)) {
            throw new ApiScopeInsufficientException;
        }

        return $next($request);
    }
}
