<?php

namespace Tests\Feature\AcademicStructure;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0D section 87: proves Phase 0D mutation routes reuse the
 * EXISTING `school-api-mutations` limiter (never a new one-per-endpoint
 * limiter) -- the actual tenant-isolation mechanics of that limiter are
 * already proven generically in
 * Tests\Feature\RateLimiting\SchoolApiMutationsThrottleTest and
 * Tests\Unit\RateLimiterKeyingTest; repeating a full 61-request proof
 * per Phase 0D endpoint would be redundant.
 */
class AcademicStructureRateLimitingTest extends TestCase
{
    #[Test]
    public function representative_phase_0d_mutation_routes_use_the_shared_school_api_mutations_limiter(): void
    {
        $routeNames = [
            'api.v1.schools.profile.update',
            'api.v1.schools.campuses.store',
            'api.v1.schools.academic-years.store',
            'api.v1.schools.academic-years.activate',
            'api.v1.schools.grade-levels.store',
            'api.v1.schools.subjects.store',
            'api.v1.schools.academic-years.sections.store',
            'api.v1.schools.academic-years.subject-offerings.store',
        ];

        foreach ($routeNames as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} must exist");
            $this->assertContains('throttle:school-api-mutations', $route->middleware(), "{$name} must use the shared school-api-mutations limiter");
        }
    }

    #[Test]
    public function no_new_per_endpoint_limiter_was_introduced_for_academic_structure_routes(): void
    {
        // Phase 8A.15 added exactly two new, deliberate, SHARED limiters
        // (never a new one-per-endpoint limiter, which is what this
        // guard actually exists to catch) for the four HR read
        // endpoints, which live under the same `api.v1.schools.*` route
        // namespace this scan covers: `hr-api-reads` (Directory/
        // Profile/Timeline) and the stricter `hr-api-sensitive-reads`
        // (Highly Sensitive document metadata) -- see
        // App\Providers\RateLimiterServiceProvider and
        // Tests\Feature\HR\HrEmployeeApiRateLimitTest.
        $registeredLimiters = ['login', 'public-api', 'school-api-mutations', 'webhook-admin', 'internal-service', 'internal-diagnostics', 'hr-api-reads', 'hr-api-sensitive-reads'];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.schools.')) {
                continue;
            }

            foreach ($route->middleware() as $middleware) {
                if (! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $limiterName = substr($middleware, strlen('throttle:'));
                $this->assertContains($limiterName, $registeredLimiters, "Unexpected new limiter '{$limiterName}' on route {$route->getName()}");
            }
        }
    }
}
