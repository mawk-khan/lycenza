<?php

namespace Tests\Feature\Api\Internal;

use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\ResolveSchoolContext;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 sections 5-9: liveness must always answer 200 (this
 * process is up), and must never leak an infrastructure detail;
 * readiness genuinely depends on PostgreSQL/Redis being reachable
 * (proven true in this suite, which itself runs against real
 * PostgreSQL/Redis, ADR 0024) and must also stay unauthenticated and
 * free of infrastructure detail in its body.
 */
class HealthControllerTest extends TestCase
{
    #[Test]
    public function liveness_returns_ok_unauthenticated(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    #[Test]
    public function liveness_response_leaks_no_infrastructure_detail(): void
    {
        $response = $this->getJson('/api/health/live');

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('postgres', strtolower($body));
        $this->assertStringNotContainsString('redis', strtolower($body));
        $this->assertStringNotContainsString('password', strtolower($body));
    }

    #[Test]
    public function readiness_returns_ok_when_dependencies_are_reachable(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    #[Test]
    public function readiness_response_leaks_no_infrastructure_detail(): void
    {
        $response = $this->getJson('/api/health/ready');

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('host', strtolower($body));
        $this->assertStringNotContainsString('password', strtolower($body));
    }

    /**
     * A real local live proof for this checkpoint found that leaving
     * `ResolveSchoolContext` (an `api`-group-global middleware that
     * unconditionally queries PostgreSQL for domain-based tenant
     * resolution) attached to the health routes made `/api/health/live`
     * hang indefinitely during a real PostgreSQL outage -- exactly the
     * failure mode liveness exists to survive. This proves the fix
     * (`routes/api.php`'s `withoutMiddleware(...)`) stays in place: a
     * health route resolving neither middleware in its computed
     * pipeline, for both routes, is a fast, deterministic proxy for
     * "will never block on tenant resolution" that doesn't require
     * actually severing the database connection inside a test.
     */
    #[Test]
    public function health_routes_never_run_tenant_resolution_middleware(): void
    {
        foreach (['api.health.live', 'api.health.ready'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertNotContains(ResolveSchoolContext::class, $middleware, "{$name} must not run ResolveSchoolContext");
            $this->assertNotContains(DevOnlySchoolHeaderResolver::class, $middleware, "{$name} must not run DevOnlySchoolHeaderResolver");
        }
    }
}
