<?php

namespace App\Support\Navigation;

use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Domains\HostClassification;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * SWC.1 (ADR 0073 sections 6.1-6.2): evaluates StaffNavigation for ONE
 * request and returns only what the actor may see -- the shared Inertia
 * `navigation` prop. HandleInertiaRequests shares it as a closure, so it
 * runs at response time, after ResolveSchoolContext/ResolvePlatformElevation/
 * RequireSchoolContext have resolved (or refused) the School.
 *
 * Context rules:
 *   - School entries need the active School from TenantContext -- never a
 *     client-supplied id -- and are evaluated against the actor's
 *     capabilities in THAT School only (CapabilityResolver's existing
 *     School-keyed cache; no cache of its own).
 *   - An elevated session gets no School entry (rule 83: elevation opens no
 *     School page, so advertising one would be false).
 *   - A School custom host gets no platform, Group or API-token entry
 *     (ADR 0054 section 8.2).
 *   - Nothing here reads a role; nothing here is authorization.
 */
final class NavigationBuilder
{
    /** POR: PortalAvailability AND a live ActingGuardian in the active School. */
    public const AVAILABILITY_GUARDIAN_PORTAL = 'guardian-portal';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
        private readonly ElevationContext $elevation,
        private readonly ActingGuardianResolver $guardians,
    ) {}

    /**
     * @return array{
     *     home: array{href: string, active: bool},
     *     school: array{name: string}|null,
     *     groups: list<array{key: string, label: string, items: list<array{key: string, label: string, href: string, section: string|null, active: bool}>}>,
     *     account: list<array{key: string, label: string, href: string, active: bool}>,
     *     activeKey: string|null
     * }|null
     */
    public function forRequest(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $schoolHost = HostClassification::schoolHostOf($request) !== null;
        $school = $this->elevation->isElevated() ? null : $this->context->school();
        $visible = $this->visibleEntries($user, $school, $schoolHost);

        $route = $request->route()?->getName();
        $activeKey = $this->activeKey($visible, is_string($route) ? $route : null);

        $groups = [];
        $account = [];

        // ORG.12F (ADR 0080 §11.4): the School's presentation profile, applied
        // AFTER every security decision above -- it can only drop or relabel.
        foreach (app(NavigationPresentation::class)->apply($visible, $school) as ['entry' => $entry, 'label' => $label]) {
            $item = [
                'key' => $entry->key,
                'label' => $label,
                'href' => route($entry->routeName, absolute: false),
                'active' => $entry->key === $activeKey,
            ];

            if ($entry->group === StaffNavigation::ACCOUNT_GROUP) {
                $account[] = $item;

                continue;
            }

            $groups[$entry->group]['items'][] = [...$item, 'section' => $entry->section];
        }

        $ordered = [];
        foreach (StaffNavigation::GROUPS as $key => $label) {
            // An empty group is never sent (ADR 0073 section 6.3).
            if (isset($groups[$key])) {
                $ordered[] = ['key' => $key, 'label' => $label, 'items' => $groups[$key]['items']];
            }
        }

        return [
            'home' => ['href' => route('app.dashboard', absolute: false), 'active' => $route === 'app.dashboard'],
            'school' => $school instanceof School ? ['name' => $school->name] : null,
            'groups' => $ordered,
            'account' => $account,
            'activeKey' => $activeKey,
        ];
    }

    /**
     * The entry keys visible to this actor in this context -- the same
     * evaluation forRequest() uses, without a request (tests, Dashboard).
     *
     * @return list<NavigationEntry>
     */
    public function visibleEntries(User $user, ?School $school, bool $schoolHost): array
    {
        // ORG.7A (ADR 0076 §11.7): an adopted capability held in at least one
        // active Campus also shows its entry. Visibility only -- every page
        // re-applies the actor's CampusScope, and nothing here authorizes.
        // ORG.7D-1 (§37): only an entry whose every capability is adopted --
        // a Campus-aware destination. A hub that also opens on unadopted keys
        // (Data health) is whole-School, so a Campus grant never shows it.
        $schoolCapabilities = $school !== null ? $this->capabilities->schoolCapabilities($user, $school) : [];
        $withCampus = $school !== null
            ? array_values(array_unique([...$schoolCapabilities, ...app(CampusScopeResolver::class)->heldInAnyCampus($user, $school)]))
            : [];
        $platformCapabilities = $schoolHost ? [] : $this->capabilities->platformCapabilities($user);
        $guardianAvailable = null;

        $visible = [];

        foreach (StaffNavigation::entries() as $entry) {
            if ($schoolHost && $entry->platformHostOnly) {
                continue;
            }

            $granted = match ($entry->scope) {
                NavigationEntry::SCOPE_SCHOOL => $school !== null && $this->satisfies($entry, $this->campusAware($entry) ? $withCampus : $schoolCapabilities),
                NavigationEntry::SCOPE_PLATFORM => $this->satisfies($entry, $platformCapabilities),
                NavigationEntry::SCOPE_GROUP => $this->groupSatisfies($user, $entry),
                NavigationEntry::SCOPE_ACCOUNT => true,
                default => false,
            };

            if (! $granted) {
                continue;
            }

            if ($entry->availability === self::AVAILABILITY_GUARDIAN_PORTAL) {
                $guardianAvailable ??= $school !== null
                    && PortalAvailability::isAvailable()
                    && $this->guardians->resolve($user, $school) !== null;

                if (! $guardianAvailable) {
                    continue;
                }
            }

            $visible[] = $entry;
        }

        return $visible;
    }

    /**
     * ORG.7D-1: every capability the entry names is Campus-adopted -- and
     * (ORG.7D-3) its destination is not a declared whole-School-only
     * operation, which a Campus grant can never open.
     */
    private function campusAware(NavigationEntry $entry): bool
    {
        $named = [...$entry->all, ...$entry->any];

        return $named !== [] && CampusScopeCatalogue::adoptedAmong($named) === $named
            && ! array_key_exists($entry->routeName, CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS);
    }

    /** @param  list<string>  $held */
    private function satisfies(NavigationEntry $entry, array $held): bool
    {
        foreach ($entry->all as $capability) {
            if (! in_array($capability, $held, true)) {
                return false;
            }
        }

        if ($entry->any !== [] && array_intersect($entry->any, $held) === []) {
            return false;
        }

        return $entry->all !== [] || $entry->any !== [];
    }

    private function groupSatisfies(User $user, NavigationEntry $entry): bool
    {
        foreach ($entry->all as $capability) {
            if ($this->capabilities->groupsWith($user, $capability)->isEmpty()) {
                return false;
            }
        }
        // ORG.12E: an any-of Group entry (the legacy Group Admin view or a Network role).
        if ($entry->any !== []) {
            foreach ($entry->any as $capability) {
                if ($this->capabilities->groupsWith($user, $capability)->isNotEmpty()) {
                    return true;
                }
            }

            return false;
        }

        return $entry->all !== [];
    }

    /**
     * The most specific visible entry whose pattern matches the current
     * route name (an exact route name beats any wildcard).
     *
     * @param  list<NavigationEntry>  $visible
     */
    private function activeKey(array $visible, ?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($visible as $entry) {
            foreach ($entry->activePatterns() as $pattern) {
                if (! Str::is($pattern, $route)) {
                    continue;
                }

                $score = $pattern === $route ? PHP_INT_MAX : strlen(rtrim($pattern, '*'));

                if ($score > $bestScore) {
                    $best = $entry->key;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }
}
