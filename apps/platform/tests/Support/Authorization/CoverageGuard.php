<?php

namespace Tests\Support\Authorization;

use Illuminate\Routing\Route;

/**
 * ORG.2 (ADR 0076 §28): the checks behind AuthorizationCoverageGuardTest,
 * taking the route list and the declared entries as input so the guard's
 * own self-test can prove it catches an uncovered route.
 */
final class CoverageGuard
{
    /**
     * @param  list<Route>  $routes
     * @param  list<Coverage>  $entries
     */
    public function __construct(private readonly array $routes, private readonly array $entries) {}

    /** @return array<string, Coverage> */
    public function byRoute(): array
    {
        $keyed = [];
        foreach ($this->entries as $entry) {
            $keyed[$entry->route] = $entry;
        }

        return $keyed;
    }

    /** @return list<string> routes that are neither School-facing nor classified as non-School (or are both) */
    public function unclassified(): array
    {
        $problems = [];
        foreach ($this->routes as $route) {
            $key = RouteUniverse::key($route);
            $school = $this->isSchoolFacing($route);
            $listed = array_key_exists($key, RouteUniverse::NON_SCHOOL);
            if ($school === $listed) {
                $problems[] = $this->describe($route).($school ? ' is School-facing AND listed as non-School' : ' is not classified');
            }
        }

        return $problems;
    }

    /** @return list<string> School-facing routes with no contract (school context or membership alone is not one) */
    public function uncovered(): array
    {
        $entries = $this->byRoute();
        $problems = [];
        foreach ($this->routes as $route) {
            if (! $this->isSchoolFacing($route)) {
                continue;
            }
            if (CoverageEvidence::routeCapabilities($route) === [] && ! isset($entries[RouteUniverse::key($route)])) {
                $problems[] = $this->describe($route).' has no authorization contract';
            }
        }

        return $problems;
    }

    /** @return list<string> entries naming a route that does not exist or is not School-facing */
    public function stale(): array
    {
        $routes = [];
        foreach ($this->routes as $route) {
            $routes[RouteUniverse::key($route)] = $route;
        }

        $problems = [];
        foreach ($this->entries as $entry) {
            $route = $routes[$entry->route] ?? null;
            if ($route === null) {
                $problems[] = "[{$entry->route}] is not a registered route";
            } elseif (! $this->isSchoolFacing($route)) {
                $problems[] = "[{$entry->route}] is not a School-facing route";
            }
        }
        foreach (array_keys(RouteUniverse::NON_SCHOOL) as $key) {
            if (! isset($routes[$key])) {
                $problems[] = "non-School [{$key}] is not a registered route";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public function duplicates(): array
    {
        $counts = array_count_values(array_map(fn (Coverage $c) => $c->route, $this->entries));

        return array_values(array_map(fn ($route) => "[{$route}] is declared more than once", array_keys(array_filter($counts, fn ($n) => $n > 1))));
    }

    /** @return list<string> entries whose evidence the code does not show */
    public function evidence(): array
    {
        $routes = [];
        foreach ($this->routes as $route) {
            $routes[RouteUniverse::key($route)] = $route;
        }

        $problems = [];
        foreach ($this->entries as $entry) {
            $route = $routes[$entry->route] ?? null;
            if ($route === null) {
                continue;
            }
            foreach (CoverageEvidence::problems($route, $entry) as $problem) {
                $problems[] = "[{$entry->route}] {$entry->class} {$problem}";
            }
            if ($entry->everyMember && ! in_array($entry->class, [Coverage::OWNED, Coverage::SPECIAL], true)) {
                $problems[] = "[{$entry->route}] only C4/C5 may be open to every member";
            }
        }

        return $problems;
    }

    private function isSchoolFacing(Route $route): bool
    {
        $middleware = $route->gatherMiddleware();

        return in_array('school-context', $middleware, true)
            || in_array('school-membership', $middleware, true)
            || (($this->byRoute()[RouteUniverse::key($route)] ?? null)?->class === Coverage::SPECIAL);
    }

    private function describe(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri().' ['.RouteUniverse::key($route).']';
    }
}
