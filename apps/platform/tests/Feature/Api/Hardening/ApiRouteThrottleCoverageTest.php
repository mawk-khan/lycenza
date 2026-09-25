<?php

namespace Tests\Feature\Api\Hardening;

use App\Http\Middleware\Api\EnforceApiCredentialScope;
use App\Http\Middleware\Api\ThrottleFailedApiAuthentication;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureIdempotent;
use App\Support\Api\PartnerScopeRegistry;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 sections 6.4, 7, 8): the route table itself --
 * every `/api/v1` route throttled, the Phase 0C.2 runtime order preserved
 * (authentication, then throttling, then scope/capability, then
 * idempotency), and no partner business route. The production-mode route
 * cache is checked by Tests\Feature\Configuration\ProductionBootSmokeTest.
 */
class ApiRouteThrottleCoverageTest extends TestCase
{
    /** @return list<Route> */
    private function v1Routes(): array
    {
        return array_values(array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            fn ($route) => str_starts_with($route->uri(), 'api/v1/'),
        ));
    }

    #[Test]
    public function every_api_v1_route_carries_a_throttle(): void
    {
        $routes = $this->v1Routes();
        $this->assertGreaterThan(300, count($routes));

        $unthrottled = [];
        foreach ($routes as $route) {
            $throttles = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            if ($throttles === []) {
                $unthrottled[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $unthrottled);

        $candidates = app(Router::class)->getRoutes()->getByName('api.v1.schools.guardian-candidates');
        $this->assertContains('throttle:api-sensitive-read', $candidates->gatherMiddleware());
    }

    #[Test]
    public function the_runtime_middleware_order_is_unchanged(): void
    {
        $router = app(Router::class);
        $store = collect($router->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/schools/{school}/campuses' && in_array('POST', $r->methods(), true));
        $sorted = array_map(fn ($m) => explode(':', $m)[0], $router->gatherRouteMiddleware($store));

        $position = fn (string $class) => array_search($class, $sorted, true);
        foreach ([ThrottleFailedApiAuthentication::class, Authenticate::class, ThrottleRequests::class, EnforceApiCredentialScope::class, EnsureCapability::class, EnsureIdempotent::class] as $class) {
            $this->assertNotFalse($position($class), "{$class} missing");
        }

        $this->assertLessThan($position(Authenticate::class), $position(ThrottleFailedApiAuthentication::class));
        $this->assertLessThan($position(ThrottleRequests::class), $position(Authenticate::class));
        $this->assertLessThan($position(EnforceApiCredentialScope::class), $position(ThrottleRequests::class));
        $this->assertLessThan($position(EnsureCapability::class), $position(ThrottleRequests::class));
        $this->assertLessThan($position(EnsureIdempotent::class), $position(EnsureCapability::class));
    }

    #[Test]
    public function the_partner_surface_has_no_business_route(): void
    {
        $this->assertSame([], PartnerScopeRegistry::PRODUCTION);

        $partner = array_values(array_filter($this->v1Routes(), fn ($r) => str_starts_with($r->uri(), 'api/v1/partner')));
        $this->assertSame(['api/v1/partner/probe'], array_map(fn ($r) => $r->uri(), $partner), 'Only the local/testing probe.');

        foreach ($partner as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:partner', $middleware);
            $this->assertContains('partner-context', $middleware);
            $this->assertContains('partner-scope:'.PartnerScopeRegistry::PROBE, $middleware);
            $this->assertStringNotContainsString('{school}', $route->uri());
        }

        // No human route accepts a partner credential.
        foreach ($this->v1Routes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/partner')) {
                $this->assertNotContains('auth:partner', $route->gatherMiddleware(), $route->uri());
            }
        }
    }
}
