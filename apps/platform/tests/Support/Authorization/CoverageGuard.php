<?php

namespace Tests\Support\Authorization;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
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
     * @param  array<string, array{capability: string, reason: string}>|null  $wholeSchoolOperations  defaults to CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS
     */
    public function __construct(
        private readonly array $routes,
        private readonly array $entries,
        private readonly ?array $wholeSchoolOperations = null,
    ) {}

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
            $entry = $entries[RouteUniverse::key($route)] ?? null;
            if (CoverageEvidence::routeCapabilities($route) === [] && $entry === null) {
                $problems[] = $this->describe($route).' has no authorization contract';
            }
            // ORG.7A: a Campus entry floor is never record authority.
            if (CoverageEvidence::campusRouteCapabilities($route) !== [] && $entry?->class !== Coverage::CAMPUS) {
                $problems[] = $this->describe($route).' has a `capability:...,campus` floor but no C6 Campus contract';
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

    /**
     * ORG.7D-0 (ADR 0076 §36): a whole-School `capability:` floor on a
     * Campus-adopted key is allowed only on a declared whole-School-only
     * operation (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS), and every
     * declaration is an exact, School-facing route that carries exactly that
     * ordinary floor -- the `capability` Gate, which a Campus grant never
     * satisfies -- with a reason, and no Campus floor or C6 contract.
     *
     * @return list<string>
     */
    public function adoptedWholeSchool(): array
    {
        $declared = $this->wholeSchoolOperations ?? CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS;
        $entries = $this->byRoute();
        $routes = [];
        foreach ($this->routes as $route) {
            $routes[RouteUniverse::key($route)] = $route;
        }

        // Every registered route, School-facing or not: ResolveSchoolContext
        // runs on the whole web and API groups, so a whole-School floor
        // works on any of them.
        $problems = [];
        foreach ($routes as $key => $route) {
            foreach (CoverageEvidence::wholeSchoolRouteFloors($route) as $floor) {
                foreach (CampusScopeCatalogue::adoptedAmong($floor) as $capability) {
                    if (($declared[$key]['capability'] ?? null) !== $capability || count($floor) !== 1) {
                        $problems[] = $this->describe($route)." checks Campus-adopted [{$capability}] with a whole-School floor but is not a declared whole-School-only operation";
                    }
                }
            }
        }

        foreach ($declared as $key => $declaration) {
            $capability = $declaration['capability'];
            $route = $routes[$key] ?? null;
            if (preg_match('/[*?]|\.$|^\s|\s$/', $key) === 1) {
                $problems[] = "[{$key}] whole-School-only operation looks like a pattern; declare exact routes";
            }
            if ($route === null) {
                $problems[] = "[{$key}] whole-School-only operation is not a registered route";

                continue;
            }
            if (! $this->isSchoolFacing($route)) {
                $problems[] = "[{$key}] whole-School-only operation is not a School-facing route";
            }
            if (! CampusScopeCatalogue::isAdopted($capability)) {
                $problems[] = "[{$key}] whole-School-only operation names [{$capability}], which is not Campus-adopted";
            }
            if (trim($declaration['reason']) === '') {
                $problems[] = "[{$key}] whole-School-only operation needs its reason";
            }
            if (! in_array("capability:{$capability}", $route->gatherMiddleware(), true)) {
                $problems[] = "[{$key}] whole-School-only operation does not carry its ordinary `capability:{$capability}` floor";
            }
            if (CoverageEvidence::campusRouteCapabilities($route) !== [] || ($entries[$key] ?? null)?->class === Coverage::CAMPUS) {
                $problems[] = "[{$key}] whole-School-only operation also carries a Campus floor or C6 contract";
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
