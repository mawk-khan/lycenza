<?php

namespace Tests\Feature\Authorization\Coverage;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\CoverageEvidence;
use Tests\Support\Authorization\PlatformRouteCoverage;
use Tests\Support\Authorization\RouteUniverse;
use Tests\TestCase;

/**
 * ORG.4 (ADR 0076 §28.7 follow-up, §30): every platform route (RouteUniverse
 * category A) carries platform-mode `capability:` middleware or a
 * PlatformRouteCoverage contract whose evidence the code shows -- the same
 * machinery as the School-route guard (rule 98). A platform route never
 * uses School-mode capability middleware.
 */
class PlatformRouteCoverageGuardTest extends TestCase
{
    /** @return array<string, Route> */
    private function platformRoutes(array $extra = []): array
    {
        $routes = [];
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $key = RouteUniverse::key($route);
            if ((RouteUniverse::NON_SCHOOL[$key][0] ?? null) === RouteUniverse::PLATFORM || in_array($key, $extra, true)) {
                $routes[$key] = $route;
            }
        }

        return $routes;
    }

    /**
     * @param  array<string, Route>  $routes
     * @return list<string>
     */
    private function uncovered(array $routes): array
    {
        $declared = array_map(fn (Coverage $c) => $c->route, PlatformRouteCoverage::entries());
        $problems = [];
        foreach ($routes as $key => $route) {
            $platformMiddleware = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'capability:') && str_ends_with($m, ',platform'));
            if ($platformMiddleware === [] && ! in_array($key, $declared, true)) {
                $problems[] = "[{$key}] has no platform authorization contract";
            }
        }

        return $problems;
    }

    #[Test]
    public function every_platform_route_has_a_platform_contract(): void
    {
        $this->assertSame([], $this->uncovered($this->platformRoutes()));
    }

    #[Test]
    public function platform_routes_never_use_school_mode_capability_middleware(): void
    {
        foreach ($this->platformRoutes() as $key => $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $this->assertStringEndsWith(',platform', $middleware, "[{$key}] must check its capability in platform mode");
                }
            }
        }
    }

    #[Test]
    public function every_platform_contract_names_a_platform_route_once_and_its_evidence_holds(): void
    {
        $routes = $this->platformRoutes();
        $entries = PlatformRouteCoverage::entries();
        $names = array_map(fn (Coverage $c) => $c->route, $entries);
        $this->assertSame(count($names), count(array_unique($names)), 'a platform route is declared twice');

        $problems = [];
        foreach ($entries as $entry) {
            if (! isset($routes[$entry->route])) {
                $problems[] = "[{$entry->route}] is not a platform route";

                continue;
            }
            foreach (CoverageEvidence::problems($routes[$entry->route], $entry) as $problem) {
                $problems[] = "[{$entry->route}] {$entry->class} {$problem}";
            }
            foreach ($entry->capabilities as $key) {
                $this->assertStringStartsWith('platform.', $key, "[{$entry->route}] a platform route gates on a platform capability");
            }
        }
        $this->assertSame([], $problems);
    }

    #[Test]
    public function a_new_platform_route_without_a_contract_is_caught(): void
    {
        RouteFacade::middleware(['web', 'auth'])->get('/app/platform/__org4-probe', fn () => 'probe')->name('org4.probe.platform');
        app(Router::class)->getRoutes()->refreshNameLookups();

        $this->assertContains('[org4.probe.platform] has no platform authorization contract', $this->uncovered($this->platformRoutes(['org4.probe.platform'])));
    }
}
