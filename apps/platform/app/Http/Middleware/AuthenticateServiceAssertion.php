<?php

namespace App\Http\Middleware;

use App\Support\ServiceAuth\ServiceAssertionReplayGuard;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceAuthenticationException;
use App\Support\ServiceAuth\ServiceAuthKeys;
use App\Support\ServiceAuth\ServiceAuthTelemetry;
use App\Support\ServiceAuth\ServiceKeyConfigException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR 0053 (Phase 0O.7A): guards Laravel's internal AI routes
 * (`/api/internal/ai/*`) with a per-request `ai-gateway` Ed25519 service
 * assertion (`Authorization: Lycenza-Service ...`). In order:
 *
 *  1-5. parse, ring lookup, signature, closed claims, request binding
 *       (ServiceAssertionVerifier);
 *  6.   one-time jti consumption in the shared cache (ServiceAssertionReplayGuard);
 *  7.   route authorization: the route NAME must be in the closed catalog
 *       (ServiceAuthContract::ROUTE_SCOPES) and its scope held by the service.
 *
 * Only then does the controller verify the ADR 0023 context token and the
 * operation's own authorization. This middleware NEVER sets TenantContext,
 * never reads a School or actor, and has no fallback: no shared token, no
 * Bearer, no IP allowlist. Authentication failure is a uniform 401;
 * an authenticated service outside the route's scope is a 403.
 */
final class AuthenticateServiceAssertion
{
    public function __construct(
        private readonly ServiceAuthKeys $keys,
        private readonly ServiceAuthTelemetry $telemetry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();
        $uri = (string) $request->server->get('REQUEST_URI', '');
        $queryAt = strpos($uri, '?');

        try {
            $verified = $this->keys->verifier()->verify(
                $request->headers->get('Authorization'),
                $request->getMethod(),
                $queryAt === false ? $uri : substr($uri, 0, $queryAt),
                // Any `?` -- even an empty query -- is refused (section 4.4).
                $queryAt === false ? '' : '?'.substr($uri, $queryAt + 1),
                $request->getContent(),
            );
            (new ServiceAssertionReplayGuard(cache()->store(config('services.ai_gateway.replay_store'))))
                ->consume($verified, now()->getTimestamp());
        } catch (ServiceAuthenticationException $e) {
            $this->telemetry->record(ServiceAuthTelemetry::INBOUND, $e->service, $e->reason, $route, $e->kid);

            return self::refuse(401, 'service_authentication_failed');
        } catch (ServiceKeyConfigException) {
            // No usable ring (the production guard refuses this at boot):
            // fail closed, never unauthenticated.
            $this->telemetry->record(ServiceAuthTelemetry::INBOUND, null, 'unknown_kid', $route);

            return self::refuse(401, 'service_authentication_failed');
        }

        $scope = ServiceAuthContract::ROUTE_SCOPES[$route] ?? null;
        if ($scope === null || ! in_array($scope, ServiceAuthContract::SERVICE_SCOPES[$verified->service] ?? [], true)) {
            $this->telemetry->record(ServiceAuthTelemetry::INBOUND, $verified->service, 'service_not_authorized', $route, $verified->kid);

            return self::refuse(403, 'service_not_authorized');
        }

        $this->telemetry->record(ServiceAuthTelemetry::INBOUND, $verified->service, 'success', $route, $verified->kid);
        $request->attributes->set('service_identity', $verified->service);

        return $next($request);
    }

    private static function refuse(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code]], $status);
    }
}
