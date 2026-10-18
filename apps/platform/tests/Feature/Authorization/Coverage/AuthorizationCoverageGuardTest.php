<?php

namespace Tests\Feature\Authorization\Coverage;

use App\Models\Capability;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\CoverageEvidence;
use Tests\Support\Authorization\CoverageGuard;
use Tests\Support\Authorization\RouteUniverse;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * ORG.2 (ADR 0076 §28, CLAUDE.md rule 98): every School-facing HTTP route
 * has an explicit, reviewable authorization contract -- C1 its own
 * `capability:` middleware, otherwise a SchoolRouteCoverage entry (C2 Gate,
 * C3 service authority, C4 scoped authority, C5 special) whose evidence the
 * code itself shows. Every other route is classified by name in
 * RouteUniverse. A new route of any kind fails here until it is placed.
 */
class AuthorizationCoverageGuardTest extends TestCase
{
    private function guard(): CoverageGuard
    {
        return new CoverageGuard(app(Router::class)->getRoutes()->getRoutes(), SchoolRouteCoverage::entries());
    }

    #[Test]
    public function every_registered_route_is_classified_exactly_once(): void
    {
        $this->assertSame([], $this->guard()->unclassified(), "Classify each route: give a School route a contract in SchoolRouteCoverage (or `capability:` middleware), or list a non-School route in RouteUniverse::NON_SCHOOL.\n");
    }

    #[Test]
    public function every_school_route_has_an_authorization_contract(): void
    {
        $this->assertSame([], $this->guard()->uncovered(), "School context/membership is not authorization. Add `capability:` middleware or a SchoolRouteCoverage entry:\n");
    }

    #[Test]
    public function every_declaration_names_a_real_route_once(): void
    {
        $this->assertSame([], [...$this->guard()->stale(), ...$this->guard()->duplicates()]);
    }

    #[Test]
    public function every_declared_contract_is_shown_by_the_code(): void
    {
        $this->assertSame([], $this->guard()->evidence(), "A declared contract no longer matches the code (CoverageEvidence):\n");
    }

    #[Test]
    public function declarations_are_exact_identities_never_patterns(): void
    {
        $keys = [...array_map(fn (Coverage $c) => $c->route, SchoolRouteCoverage::entries()), ...array_keys(RouteUniverse::NON_SCHOOL)];

        foreach ($keys as $key) {
            $this->assertDoesNotMatchRegularExpression('/[*?]|\.$|^\s|\s$/', $key, "[{$key}] looks like a pattern; coverage is declared per route.");
        }
    }

    #[Test]
    public function declared_capabilities_are_existing_school_capabilities(): void
    {
        $catalogue = Capability::query()->pluck('namespace', 'key')->all();

        foreach (SchoolRouteCoverage::entries() as $entry) {
            foreach ($entry->capabilities as $key) {
                $this->assertArrayHasKey($key, $catalogue, "[{$entry->route}] names [{$key}], which is not in the capability catalogue -- never invent a capability for coverage.");
                $this->assertContains($catalogue[$key], ['school', 'guardian'], "[{$entry->route}] gates on [{$key}], which is not a School capability.");
            }
        }
    }

    #[Test]
    public function route_capability_middleware_names_existing_capabilities(): void
    {
        $catalogue = Capability::query()->pluck('namespace', 'key')->all();

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            foreach (CoverageEvidence::routeCapabilities($route) as $middleware) {
                foreach (explode('|', $middleware) as $key) {
                    $this->assertArrayHasKey($key, $catalogue, '['.RouteUniverse::key($route)."] capability middleware names [{$key}], which is not in the capability catalogue.");
                }
            }
        }
    }

    #[Test]
    public function special_contracts_stay_few_and_name_their_governance(): void
    {
        $special = array_values(array_filter(SchoolRouteCoverage::entries(), fn (Coverage $c) => $c->class === Coverage::SPECIAL));

        $this->assertSame(array_keys(SchoolRouteCoverage::special()), array_map(fn (Coverage $c) => $c->route, $special));
        $this->assertLessThanOrEqual(10, count($special), 'C5 is not a generic escape hatch (ADR 0076 §28).');

        foreach ($special as $entry) {
            $this->assertNotEmpty($entry->governedBy);
            $this->assertNotEmpty($entry->reason);
            $this->assertTrue(class_exists((string) $entry->evidence), "[{$entry->route}] evidence test is missing.");
        }
    }

    #[Test]
    public function a_route_naming_a_school_is_school_facing_or_platform_governed(): void
    {
        $guard = $this->guard();
        $entries = $guard->byRoute();

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (! in_array('school', $route->parameterNames(), true)) {
                continue;
            }
            $key = RouteUniverse::key($route);
            $middleware = $route->gatherMiddleware();
            $schoolFacing = in_array('school-membership', $middleware, true) || ($entries[$key] ?? null)?->class === Coverage::SPECIAL;

            $this->assertTrue(
                $schoolFacing || (RouteUniverse::NON_SCHOOL[$key][0] ?? null) === RouteUniverse::PLATFORM,
                "[{$key}] takes a {school} parameter but is neither a School API route, a C5 context route, nor platform-governed.",
            );
        }
    }
}
