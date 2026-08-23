<?php

namespace App\Http\Middleware;

use App\Support\ServiceIdentities\ServiceIdentityAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /api/internal/ai/* surface. Section 25-27: authenticating
 * successfully does NOT mean "access everything" -- the presented
 * credential resolves to a real ServiceIdentity (never re-used from
 * `users`), which must be enabled AND hold the SPECIFIC capability the
 * route requires (`capability:` parameter, mirroring
 * App\Http\Middleware\EnsureCapability's pattern for humans). Denies a
 * correctly-authenticated-but-uncapable service identity just as
 * firmly as an unauthenticated one -- proven in
 * ServiceIdentityAuthorizationTest.
 *
 * This is service-to-service AUTHENTICATION plus COARSE capability
 * gating only; it does NOT authorize any specific School/actor action
 * -- that is AiContextTokenService's job (ADR 0023,
 * App\Http\Controllers\Api\Internal\AiToolController).
 */
class VerifyAiGatewayServiceToken
{
    public function __construct(private readonly ServiceIdentityAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next, string $capability = 'ai.tools.invoke'): Response
    {
        $identity = $this->authenticator->resolve($request->bearerToken());

        if (! $this->authenticator->authorize($identity, $capability)) {
            abort(401, 'Invalid or unauthorized AI Gateway service credential.');
        }

        $request->attributes->set('service_identity', $identity);

        return $next($request);
    }
}
