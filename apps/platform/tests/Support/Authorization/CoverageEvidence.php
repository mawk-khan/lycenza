<?php

namespace Tests\Support\Authorization;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkBrandService;
use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * ORG.2 (ADR 0076 §28): checks a declared Coverage against the code it
 * names -- never against comments. Returns the problems found (empty when
 * the evidence holds).
 *
 *   C2: the action, or a `$this->helper()` it calls unconditionally, makes
 *       an unconditional `capability` Gate call (authorizeCapability(),
 *       authorizeAnyCapability(), Gate::authorize(), Gate::forUser()->
 *       authorize(), or a Gate denies() refusal) whose keys include the
 *       declared ones;
 *   C3: the action calls the declared method, and that method authorizes
 *       (an authorize*() / Gate call within three hops of its own code);
 *   C4: the action calls the declared authority method (and gates on the
 *       declared base capability, if any);
 *   C5: governing invariant, reason and an existing evidence test;
 *   C6: the key is Campus-adopted, the action unconditionally calls
 *       authorizeCampusScope() with it, and calls the declared domain access
 *       method (or, for a declared shared lookup, gives its reason); a
 *       `capability:...,campus` route floor names the same key.
 */
final class CoverageEvidence
{
    /**
     * Domain authorities whose methods ARE the actor authorization, with
     * stronger semantics than the bare `capability` Gate (a disabled User is
     * refused; grant rights are evaluated), so they are deliberately not
     * routed through it (ADR 0059, ADR 0071 §24, rule 94).
     */
    private const DOMAIN_AUTHORITIES = [
        StaffRoleCatalog::class => ['canView', 'canManageMembers', 'canManageRoles', 'canAdminister', 'requireIssuer', 'requireMemberManager', 'requireRoleManager'],
        // ORG.4 (ADR 0076 §28.7 follow-up): the platform governance services'
        // own capability checks (ADR 0045, ADR 0046).
        PlatformRoleGovernanceService::class => ['requireCapability'],
        SchoolGroupGovernanceService::class => ['require', 'requireMayManage'],
        // ORG.12E (ADR 0080 §10): the bounded Network-governance resolver and
        // the Network-role writer's floor (platform OR a whole-Group grant).
        GroupGovernanceAccess::class => ['mayManage', 'mayManageOperators', 'requireManageNow', 'requireOperatorsManageNow'],
        NetworkAccessService::class => ['assertMayAdminister'],
        // ORG.12F (ADR 0080 §11.7): the Network brand writer's floor.
        NetworkBrandService::class => ['assertMayManage'],
        // ADR 0048: Group-safe reporting needs an unrevoked group.reporting.view grant.
        GroupCurriculumCoverageReportService::class => ['authorizingGrant'],
        // ORG.5 (ADR 0076 §31): platform.school_entitlements.manage, audited when refused.
        SchoolModuleEntitlementService::class => ['requireManage'],
    ];

    /** @return list<string> */
    public static function problems(Route $route, Coverage $coverage): array
    {
        if ($coverage->class === Coverage::SPECIAL) {
            return self::specialProblems($coverage);
        }
        $action = self::action($route);
        if ($action === null) {
            return ['is not a controller action, so its contract cannot be checked'];
        }

        return match ($coverage->class) {
            Coverage::GATE => self::gateProblems($action, $coverage->capabilities),
            Coverage::SERVICE => self::serviceProblems($action, $coverage),
            Coverage::OWNED => [
                ...self::authorityProblems($action, $coverage),
                ...($coverage->capabilities === [] || self::routeCapabilities($route) !== []
                    ? []
                    : self::gateProblems($action, $coverage->capabilities)),
            ],
            Coverage::SPECIAL => self::specialProblems($coverage),
            Coverage::CAMPUS => self::campusProblems($route, $action, $coverage),
            default => ["unknown coverage class {$coverage->class}"],
        };
    }

    /** @return list<string> */
    private static function campusProblems(Route $route, ReflectionMethod $action, Coverage $coverage): array
    {
        $key = $coverage->capabilities[0] ?? '';
        $problems = [];
        if (! CampusScopeCatalogue::isAdopted($key)) {
            $problems[] = "names [{$key}], which is not adopted for Campus scope (CampusScopeCatalogue)";
        }
        if (! in_array($key, self::campusKeys($action), true)) {
            $problems[] = "does not resolve the actor's CampusScope for [{$key}] unconditionally (authorizeCampusScope)";
        }
        if ($coverage->authorities !== []) {
            $problems = [...$problems, ...self::authorityProblems($action, $coverage)];
        } elseif (trim((string) $coverage->reason) === '') {
            $problems[] = 'a Campus shared lookup needs its reason';
        }
        foreach (self::campusRouteCapabilities($route) as $floor) {
            if ($floor !== $key) {
                $problems[] = "route floor capability:{$floor},campus differs from the declared [{$key}]";
            }
        }

        return $problems;
    }

    /** @return list<string> keys the action passes to authorizeCampusScope() / authorizeCampusScopeFor() unconditionally */
    private static function campusKeys(ReflectionMethod $action): array
    {
        $class = $action->getDeclaringClass();
        $keys = [];
        foreach (SourceCalls::of($action) as $call) {
            if ($call['unconditional'] && $call['receiver'] === '$this' && in_array($call['name'], ['authorizeCampusScope', 'authorizeCampusScopeFor'], true)) {
                $args = self::splitTopLevel($call['args']);
                $keys = [...$keys, ...self::resolve($call['name'] === 'authorizeCampusScopeFor' ? ($args[1] ?? '') : ($args[0] ?? ''), $class)];
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> the keys of the route's `capability:x,campus` middleware (ORG.7A entry floor) */
    public static function campusRouteCapabilities(Route $route): array
    {
        $keys = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                $parts = explode(',', substr($middleware, strlen('capability:')));
                if (($parts[1] ?? null) === 'campus') {
                    $keys[] = $parts[0];
                }
            }
        }

        return $keys;
    }

    /**
     * ORG.7D-0: the route's whole-School `capability:` floors, each as its
     * list of any-of keys. EnsureCapability treats every mode other than
     * `campus` and `platform` (none, or an unknown one) as the whole-School
     * `capability` Gate, so this does too.
     *
     * @return list<list<string>>
     */
    public static function wholeSchoolRouteFloors(Route $route): array
    {
        $floors = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                $parts = explode(',', substr($middleware, strlen('capability:')));
                if (! in_array($parts[1] ?? null, ['campus', 'platform'], true)) {
                    $floors[] = explode('|', $parts[0]);
                }
            }
        }

        return $floors;
    }

    /** @return list<string> the capability keys of the route's own `capability:` middleware */
    public static function routeCapabilities(Route $route): array
    {
        $keys = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                $keys[] = explode(',', substr($middleware, strlen('capability:')))[0];
            }
        }

        return $keys;
    }

    /**
     * Every capability key the action gates on unconditionally (directly
     * or through one helper), resolved from the code.
     *
     * @return list<string>
     */
    public static function gatedKeys(ReflectionMethod $action): array
    {
        $keys = [];
        $class = $action->getDeclaringClass();

        foreach (SourceCalls::of($action) as $call) {
            if (! $call['unconditional']) {
                continue;
            }
            if (self::isGate($call)) {
                $keys = [...$keys, ...self::keysFrom($call, $class)];

                continue;
            }
            if ($call['receiver'] === '$this' && $class->hasMethod($call['name']) && $call['name'] !== $action->getName()) {
                $helper = $class->getMethod($call['name']);
                foreach (SourceCalls::of($helper) as $inner) {
                    if ($inner['unconditional'] && self::isGate($inner)) {
                        $resolved = self::keysFrom($inner, $helper->getDeclaringClass());
                        // A helper gating on its parameter takes the key from the call site.
                        $keys = [...$keys, ...($resolved !== [] ? $resolved : self::constantsIn($call['args'], $class))];
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public static function action(Route $route): ?ReflectionMethod
    {
        $name = $route->getActionName();
        if ($name === 'Closure' || ! str_contains($name, '@') && ! class_exists($name)) {
            return null;
        }
        [$class, $method] = str_contains($name, '@') ? explode('@', $name) : [$name, '__invoke'];

        return method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    private static function gateProblems(ReflectionMethod $action, array $declared): array
    {
        if ($declared === []) {
            return ['declares no capability key'];
        }
        $found = self::gatedKeys($action);
        if ($found === []) {
            return ['makes no unconditional capability Gate call'];
        }
        $missing = array_values(array_diff($declared, $found));

        return $missing === [] ? [] : ['does not gate on ['.implode(', ', $missing).'] (gates on ['.implode(', ', $found).'])'];
    }

    /** @return list<string> */
    private static function serviceProblems(ReflectionMethod $action, Coverage $coverage): array
    {
        $problems = self::authorityProblems($action, $coverage);
        [$service, $method] = $coverage->authorities[0];
        if ($problems === [] && ! self::authorizes(new ReflectionMethod($service, $method), 0)) {
            $problems[] = "names {$service}::{$method}(), which does not authorize the actor";
        }
        if (! str_starts_with($service, 'App\\Domain\\') && ! str_starts_with($service, 'App\\Support\\')) {
            $problems[] = "names {$service}, which is not an Application/Domain or Support service";
        }

        return $problems;
    }

    /** @return list<string> */
    private static function authorityProblems(ReflectionMethod $action, Coverage $coverage): array
    {
        $problems = [];
        foreach ($coverage->authorities as [$class, $method]) {
            if (! method_exists($class, $method)) {
                $problems[] = "names {$class}::{$method}(), which does not exist";

                continue;
            }
            if (! self::calls($action, $method)) {
                $problems[] = "never calls {$method}() of ".class_basename($class);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function specialProblems(Coverage $coverage): array
    {
        $problems = [];
        if (trim((string) $coverage->governedBy) === '' || trim((string) $coverage->reason) === '') {
            $problems[] = 'C5 needs its governing ADR/invariant and a reason';
        }
        if ($coverage->evidence === null || ! class_exists($coverage->evidence)) {
            $problems[] = 'C5 evidence test '.($coverage->evidence ?? '(none)').' does not exist';
        }

        return $problems;
    }

    /** The action, or a `$this->helper()` it calls, calls ->$method() / ::$method(). */
    private static function calls(ReflectionMethod $action, string $method): bool
    {
        $class = $action->getDeclaringClass();
        foreach (SourceCalls::of($action) as $call) {
            if ($call['name'] === $method && $call['receiver'] !== '') {
                return true;
            }
            if ($call['receiver'] === '$this' && $class->hasMethod($call['name']) && $call['name'] !== $action->getName()) {
                foreach (SourceCalls::of($class->getMethod($call['name'])) as $inner) {
                    if ($inner['name'] === $method && $inner['receiver'] !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Within three hops of its own code, $method makes an authorize*() or Gate call. */
    public static function authorizes(ReflectionMethod $method, int $depth = 0): bool
    {
        $class = $method->getDeclaringClass();
        if (in_array($method->getName(), self::DOMAIN_AUTHORITIES[$class->getName()] ?? [], true)) {
            return true;
        }
        $properties = self::propertyTypes($class);

        foreach (SourceCalls::of($method) as $call) {
            if (str_starts_with($call['name'], 'authorize') || str_contains($call['receiver'], 'Gate')) {
                return true;
            }
            if ($depth >= 3) {
                continue;
            }
            if ($call['receiver'] === '$this' && $class->hasMethod($call['name']) && $call['name'] !== $method->getName()) {
                if (self::authorizes($class->getMethod($call['name']), $depth + 1)) {
                    return true;
                }
            }
            $type = $properties[$call['receiver']] ?? null;
            if ($type !== null && str_starts_with($type, 'App\\') && method_exists($type, $call['name'])) {
                if (self::authorizes(new ReflectionMethod($type, $call['name']), $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> `$this->prop` => class */
    private static function propertyTypes(ReflectionClass $class): array
    {
        $types = [];
        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $types['$this->'.$property->getName()] = $type->getName();
            }
        }

        return $types;
    }

    /** @param array{receiver: string, name: string, args: string, unconditional: bool} $call */
    private static function isGate(array $call): bool
    {
        if ($call['receiver'] === '$this' && in_array($call['name'], ['authorizeCapability', 'authorizeAnyCapability'], true)) {
            return true;
        }

        return str_starts_with($call['receiver'], 'Gate')
            && in_array($call['name'], ['authorize', 'denies'], true)
            && str_starts_with(ltrim($call['args']), "'capability'");
    }

    /**
     * @param  array{receiver: string, name: string, args: string, unconditional: bool}  $call
     * @return list<string>
     */
    private static function keysFrom(array $call, ReflectionClass $class): array
    {
        $args = self::splitTopLevel($call['args']);
        if ($call['receiver'] !== '$this') {
            // Gate form: ('capability', [KEY, $school])
            $array = self::splitTopLevel(trim($args[1] ?? '', '[] '));

            return self::resolve($array[0] ?? '', $class);
        }

        return self::resolve($args[0] ?? '', $class);
    }

    /** @return list<string> every resolvable key expression among the arguments */
    private static function constantsIn(string $args, ReflectionClass $class): array
    {
        $keys = [];
        foreach (self::splitTopLevel($args) as $arg) {
            $keys = [...$keys, ...self::resolve($arg, $class)];
        }

        return $keys;
    }

    /**
     * A key expression: 'literal', Name::CONST, Name::staticCall('literal'),
     * or a [...] list of those.
     *
     * @return list<string>
     */
    private static function resolve(string $expression, ReflectionClass $class): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return [];
        }
        if (str_starts_with($expression, '[')) {
            $keys = [];
            foreach (self::splitTopLevel(substr($expression, 1, -1)) as $item) {
                $keys = [...$keys, ...self::resolve($item, $class)];
            }

            return $keys;
        }
        if (preg_match("/^'([^']*)'$/", $expression, $m)) {
            return [$m[1]];
        }
        if (preg_match('/^([\w\\\\]+) :: (\w+)$/', $expression, $m)) {
            $owner = self::className($m[1], $class);
            $value = $owner !== null && defined($owner.'::'.$m[2]) ? constant($owner.'::'.$m[2]) : null;

            return is_string($value) ? [$value] : (is_array($value) ? array_values(array_filter($value, 'is_string')) : []);
        }
        if (preg_match("/^([\\w\\\\]+) :: (\\w+) \\( '([^']*)' \\)$/", $expression, $m)) {
            $owner = self::className($m[1], $class);
            $value = $owner !== null && method_exists($owner, $m[2]) ? $owner::{$m[2]}($m[3]) : null;

            return is_array($value) ? array_values(array_filter($value, 'is_string')) : (is_string($value) ? [$value] : []);
        }

        return [];
    }

    private static function className(string $name, ReflectionClass $class): ?string
    {
        if (in_array($name, ['self', 'static'], true)) {
            return $class->getName();
        }
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;/m', (string) file_get_contents((string) $class->getFileName()), $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $short = $use[2] ?? substr((string) strrchr('\\'.$use[1], '\\'), 1);
            if ($short === $name) {
                return $use[1];
            }
        }
        $sibling = $class->getNamespaceName().'\\'.$name;

        return class_exists($sibling) || interface_exists($sibling) ? $sibling : null;
    }

    /** @return list<string> comma-separated parts at bracket depth zero */
    private static function splitTopLevel(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $quoted = false;
        foreach (str_split($text) as $char) {
            if ($char === "'") {
                $quoted = ! $quoted;
            }
            if (! $quoted && in_array($char, ['(', '['], true)) {
                $depth++;
            } elseif (! $quoted && in_array($char, [')', ']'], true)) {
                $depth--;
            }
            if (! $quoted && $char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
