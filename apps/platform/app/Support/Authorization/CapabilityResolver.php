<?php

namespace App\Support\Authorization;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\Campus;
use App\Models\GroupRoleAssignment;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

/**
 * The single authoritative service computing effective capabilities.
 * See docs/security/AUTHORIZATION.md.
 *
 * Platform capabilities and School capabilities are resolved and
 * cached SEPARATELY and never merged -- a platform capability grant
 * never implies a school.* capability (section 12/30: no invisible
 * "see every tenant row" mode). Cache keys always include every
 * security-relevant dimension (user, school) -- see
 * docs/architecture/adr/0022-tenant-context-propagation.md,
 * "tenant-aware cache" -- so permissions for User X in School A can
 * never be served from School B's cache entry.
 *
 * Phase 0N.5 (ADR 0045): a third, equally separate GROUP side. Group
 * capabilities (`group.*`) come only from active Group grants
 * (`group_role_assignments`) for one explicitly named School Group, are
 * resolved UNCACHED (a revoked grant or an archived Group stops counting
 * on the very next check), and are reachable only through the explicit
 * Group API below -- never through can(), so no School-scoped or
 * platform-scoped check can be satisfied by a Group grant, and neither
 * other side ever reads one.
 */
class CapabilityResolver
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SchoolModuleEntitlements $entitlements,
    ) {}

    /**
     * @return array<int, string>
     */
    public function platformCapabilities(User $actor): array
    {
        if ($actor->isDisabled() || self::isBearerAuthenticated($actor)) {
            return [];
        }

        return Cache::remember(
            "platform:capabilities:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            // Revoked grants are history, never authority (ADR 0046).
            fn () => $actor->platformRoleAssignments()
                ->whereNull('revoked_at')
                ->with('role.capabilities')
                ->get()
                ->flatMap(fn ($assignment) => $assignment->role->capabilities->pluck('key'))
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function schoolCapabilities(User $actor, School $school): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        return $this->entitled($school, $this->grantedSchoolCapabilities($actor, $school));
    }

    /**
     * The subset of $capabilities the actor effectively holds in the School
     * -- exactly `array_intersect($capabilities, schoolCapabilities())`, but
     * a check of core-module capabilities alone needs no entitlement lookup
     * (ORG.5; e.g. SchoolAdministrators, read per School by the registry).
     *
     * @param  list<string>  $capabilities
     * @return array<int, string>
     */
    public function schoolCapabilitiesAmong(User $actor, School $school, array $capabilities): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        return $this->entitled($school, array_values(array_intersect($this->grantedSchoolCapabilities($actor, $school), $capabilities)));
    }

    /**
     * ORG.5 (ADR 0076 §12.1, §12.7): effective = granted ∩ the capabilities
     * of the School's entitled modules. The grant set stays cached per user;
     * the entitled module set is cached per School and forgotten the moment
     * an entitlement changes, and is read only when a capability needs an
     * optional module (core modules are always available); the intersection
     * is computed here, in memory, on every call -- no query per capability.
     * A capability whose module mapping is missing is unavailable (fail
     * closed).
     *
     * @param  array<int, string>  $granted
     * @return array<int, string>
     */
    private function entitled(School $school, array $granted): array
    {
        $modules = null;

        return array_values(array_filter($granted, function (string $capability) use ($school, &$modules): bool {
            $required = ModuleCatalogue::modulesFor($capability);
            if ($required === []) {
                return false;
            }
            $optional = array_filter($required, fn (string $module): bool => ! ModuleCatalogue::isCore($module));
            if ($optional === []) {
                return true;
            }
            $modules ??= $this->entitlements->entitledModules($school);

            return array_diff($optional, $modules) === [];
        }));
    }

    /**
     * The capabilities the actor's active grants give in the School, before
     * module entitlements are applied (cached 60 s per School and user).
     *
     * @return array<int, string>
     */
    private function grantedSchoolCapabilities(User $actor, School $school): array
    {
        return $this->grants($actor, $school)['school'];
    }

    /**
     * The actor's active grants in the School, read ONCE and cached 60 s
     * (`school:{id}:grants:user:{uid}` -- a new key for the new shape, so a
     * pre-ORG.7A cache entry is never misread): the whole-School capabilities
     * and, separately, the ADOPTED capabilities of each Campus-scoped grant
     * (ORG.7A, ADR 0076 §11.3). The two halves are never merged: only
     * 'school' feeds schoolCapabilities()/can(); 'campus' is read only by
     * campusCapabilities().
     *
     * ORG.10-R1 (ADR 0076 §79, OD-9.7): the one exception -- an active
     * Campus-scoped grant on an ACTIVE Campus also activates, School-wide,
     * exactly the scope-independent consumer capabilities its role carries
     * (ConsumerCapabilities: own Communications, the School calendar). No
     * other key of a Campus grant ever reaches 'school'.
     *
     * @return array{school: list<string>, campus: array<string, list<string>>}
     */
    private function grants(User $actor, School $school): array
    {
        return Cache::remember(
            "school:{$school->id}:grants:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($actor, $school): array {
                $membership = SchoolMembership::query()
                    ->where('user_id', $actor->id)
                    ->where('school_id', $school->id)
                    ->active()
                    ->first();

                if ($membership === null) {
                    return ['school' => [], 'campus' => []];
                }

                // MembershipRoleAssignment is RLS-protected: read it
                // authoritatively for $school regardless of whatever
                // ambient TenantContext the caller currently has. See
                // TenantContext::withSchool()'s docblock.
                $assignments = $this->context->withSchool(
                    $school,
                    fn () => MembershipRoleAssignment::query()
                        ->where('school_membership_id', $membership->id)
                        // Revoked grants are history, never authority
                        // (Phase 0O.12B, ADR 0059 owner amendment).
                        ->active()
                        ->with('role.capabilities')
                        ->get(),
                );

                $wholeSchool = [];
                $campus = [];
                $consumer = [];
                foreach ($assignments as $assignment) {
                    $keys = $assignment->role->capabilities->pluck('key')->all();
                    if ($assignment->campus_id === null) {
                        array_push($wholeSchool, ...$keys);

                        continue;
                    }
                    // ORG.7A: a Campus-scoped grant NEVER feeds a whole-School
                    // check -- only its adopted capabilities, for that Campus.
                    $adopted = CampusScopeCatalogue::adoptedAmong($keys);
                    if ($adopted !== []) {
                        $campus[$assignment->campus_id] = array_values(array_unique([...($campus[$assignment->campus_id] ?? []), ...$adopted]));
                    }
                    // ...except the scope-independent consumer keys (OD-9.7).
                    $consumerKeys = ConsumerCapabilities::scopeIndependentAmong($keys);
                    if ($consumerKeys !== []) {
                        $consumer[$assignment->campus_id] = array_values(array_unique([...($consumer[$assignment->campus_id] ?? []), ...$consumerKeys]));
                    }
                }

                // OD-9.7: only grants on an ACTIVE Campus activate a consumer
                // key (one query, only for an actor holding such a grant).
                if ($consumer !== []) {
                    $activeCampuses = $this->context->withSchool($school, fn (): array => Campus::query()
                        ->where('school_id', $school->id)
                        ->whereIn('id', array_keys($consumer))
                        ->where('status', 'active')
                        ->pluck('id')
                        ->all());
                    foreach ($activeCampuses as $campusId) {
                        array_push($wholeSchool, ...$consumer[$campusId]);
                    }
                }

                return ['school' => array_values(array_unique($wholeSchool)), 'campus' => $campus];
            },
        );
    }

    /**
     * ORG.7A (ADR 0076 §11.3, §11.6, §33): the actor's Campus-scoped
     * authority in the School -- ACTIVE Campus id => the ADOPTED capabilities
     * (CampusScopeCatalogue) its active Campus-scoped grants carry, module
     * entitlements applied. Nothing for a disabled account or a membership
     * that is not active. A Campus that is inactive confers nothing (no new
     * work, and for the ORG.7A domains no reads either). Read only by
     * CampusScopeResolver; never merged into schoolCapabilities().
     *
     * The grants come from the same cached read as the whole-School
     * capabilities (so an actor without Campus grants costs nothing extra);
     * the Campus status is re-read on every call, so an inactivation takes
     * effect at once.
     *
     * @return array<string, list<string>>
     */
    public function campusCapabilities(User $actor, School $school): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        $grants = $this->grants($actor, $school)['campus'];
        if ($grants === []) {
            return [];
        }

        $active = $this->context->withSchool($school, fn (): array => Campus::query()
            ->where('school_id', $school->id)
            ->whereIn('id', array_keys($grants))
            ->where('status', 'active')
            ->pluck('id')
            ->all());

        $result = [];
        foreach ($active as $campusId) {
            $entitled = $this->entitled($school, $grants[$campusId] ?? []);
            if ($entitled !== []) {
                $result[$campusId] = $entitled;
            }
        }

        return $result;
    }

    /**
     * The actor's capabilities in ONE School Group: the union over their
     * unrevoked grants for that Group, and none at all for a disabled
     * account or a Group that is not active. Uncached (ADR 0045 section 10).
     *
     * @return array<int, string>
     */
    public function groupCapabilities(User $actor, SchoolGroup $group): array
    {
        return $this->groupCapabilitiesById($actor, $group->id);
    }

    /**
     * groupCapabilities() by Group id -- for a caller that must not depend
     * on the School Group model (Phase 0N.11: Analytics' Group-safe report
     * gate re-checks `group.reporting.view`, ADR 0048 section 7).
     *
     * @return array<int, string>
     */
    public function groupCapabilitiesById(User $actor, string $groupId): array
    {
        if ($actor->isDisabled() || self::isBearerAuthenticated($actor)) {
            return [];
        }

        return GroupRoleAssignment::query()
            ->active()
            ->where('group_role_assignments.user_id', $actor->id)
            ->where('group_role_assignments.school_group_id', $groupId)
            ->join('school_groups', 'school_groups.id', '=', 'group_role_assignments.school_group_id')
            ->where('school_groups.status', SchoolGroup::STATUS_ACTIVE)
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'group_role_assignments.role_id')
            ->distinct()
            ->orderBy('role_capabilities.capability_key')
            ->pluck('role_capabilities.capability_key')
            ->all();
    }

    public function canInGroup(User $actor, string $capability, SchoolGroup $group): bool
    {
        return in_array($capability, $this->groupCapabilities($actor, $group), true);
    }

    public function canInGroupById(User $actor, string $capability, string $groupId): bool
    {
        return in_array($capability, $this->groupCapabilitiesById($actor, $groupId), true);
    }

    /**
     * The active Groups in which the actor holds $capability -- only their
     * own Groups, never a directory.
     *
     * @return Collection<int, SchoolGroup>
     */
    public function groupsWith(User $actor, string $capability): Collection
    {
        if ($actor->isDisabled() || self::isBearerAuthenticated($actor)) {
            return new Collection;
        }

        return SchoolGroup::query()
            ->where('status', SchoolGroup::STATUS_ACTIVE)
            ->whereIn('id', GroupRoleAssignment::query()
                ->active()
                ->where('group_role_assignments.user_id', $actor->id)
                ->join('role_capabilities', 'role_capabilities.role_id', '=', 'group_role_assignments.role_id')
                ->where('role_capabilities.capability_key', $capability)
                ->select('group_role_assignments.school_group_id'))
            ->orderBy('name')
            ->get()
            ->toBase();
    }

    /**
     * Phase 0O.3 (ADR 0049 section 2, invariant 24): an actor authenticated
     * by a human API token (a Sanctum PersonalAccessToken -- a browser
     * session carries none, or a TransientToken) never exercises platform
     * or Group authority: that authority is bound to interactive MFA (ADR
     * 0044-0046), which a bearer token cannot carry. School capabilities
     * are unaffected.
     */
    public static function isBearerAuthenticated(User $actor): bool
    {
        /** @var mixed $token null, a TransientToken (session) or a PersonalAccessToken (bearer) */
        $token = $actor->currentAccessToken();

        return $token instanceof PersonalAccessToken;
    }

    public function canPlatform(User $actor, string $capability): bool
    {
        return in_array($capability, $this->platformCapabilities($actor), true);
    }

    public function canInSchool(User $actor, string $capability, School $school): bool
    {
        return in_array($capability, $this->schoolCapabilities($actor, $school), true);
    }

    /**
     * ORG.8A (ADR 0076 §58): whether the actor holds $capability in the School
     * NOW -- the same whole-School rule as canInSchool(), but read fresh (never
     * the 60 s grant cache or the entitlement cache). The active membership
     * and its active grants are share-locked until the caller's transaction
     * ends, so a concurrent suspension or revocation is ordered strictly
     * before or after the caller's decision; the User's disabled flag and the
     * module entitlement are read fresh at decision time, not locked.
     * Disabled Users, Campus-scoped grants and capabilities of a disabled
     * module never count. For approval
     * decisions (`ApprovalDecisionAuthority`); everything else keeps can().
     * Lock order matches StaffAccessService: membership, then grants.
     */
    public function holdsInSchoolNow(User $actor, string $capability, School $school): bool
    {
        return $this->holdsNow($actor, $capability, $school, null);
    }

    /**
     * ORG.9C-2 (ADR 0076 §70): holdsInSchoolNow() for an action on ONE
     * Campus's record -- a whole-School grant, or, for a Campus-ADOPTED
     * capability only (CampusScopeCatalogue), an active grant scoped to
     * exactly $campusId while that Campus is active (share-locked with the
     * grants). Read fresh, never the cache. $campusId null (a School-wide
     * record) is exactly holdsInSchoolNow(): a Campus grant never reaches
     * it. For direct-assured actions on a Campus record
     * (the approval authority's requireCurrent() with a Campus).
     */
    public function holdsInCampusNow(User $actor, string $capability, School $school, ?string $campusId): bool
    {
        return $this->holdsNow($actor, $capability, $school, $campusId);
    }

    private function holdsNow(User $actor, string $capability, School $school, ?string $campusId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CapabilityResolver::holdsInSchoolNow() must run inside the decision transaction.');
        }

        if (! User::query()->whereKey($actor->id)->where('is_disabled', false)->exists()) {
            return false;
        }

        $required = ModuleCatalogue::modulesFor($capability);
        if ($required === []) {
            return false;
        }

        $campusGrantCounts = $campusId !== null && CampusScopeCatalogue::isAdopted($capability);

        return $this->context->withSchool($school, function () use ($actor, $capability, $school, $required, $campusId, $campusGrantCounts): bool {
            $membership = SchoolMembership::query()
                ->where('school_id', $school->id)
                ->where('user_id', $actor->id)
                ->active()
                ->sharedLock()
                ->first();
            if ($membership === null) {
                return false;
            }

            $held = MembershipRoleAssignment::query()
                ->where('school_membership_id', $membership->id)
                ->active()
                ->where(fn ($q) => $q->whereNull('campus_id')->when($campusGrantCounts, fn ($w) => $w->orWhere('campus_id', $campusId)))
                ->sharedLock()
                ->with('role.capabilities')
                ->get()
                ->filter(fn (MembershipRoleAssignment $grant): bool => $grant->role->capabilities->contains('key', $capability));
            if ($held->isEmpty()) {
                return false;
            }
            // A Campus-scoped grant confers nothing on an inactive Campus.
            if ($held->every(fn (MembershipRoleAssignment $grant): bool => $grant->campus_id !== null)
                && ! Campus::query()->where('school_id', $school->id)->whereKey($campusId)->where('status', 'active')->sharedLock()->exists()) {
                return false;
            }

            foreach ($required as $module) {
                if (! ModuleCatalogue::isCore($module) && ! $this->entitlements->isEnabledFresh($school, $module)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Dispatches to platform or school resolution based on the
     * capability's own namespace prefix, so callers don't need to know
     * which table backs a given capability. A "platform.*" capability
     * is never satisfied by a school-scoped grant and vice versa.
     */
    public function can(User $actor, string $capability, ?School $school = null): bool
    {
        if (str_starts_with($capability, 'platform.')) {
            return $this->canPlatform($actor, $capability);
        }

        // A Group capability is only ever answered for an explicitly named
        // Group (canInGroup()) -- never here, where the only scope on offer
        // is a School.
        if (str_starts_with($capability, 'group.')) {
            return false;
        }

        if ($school === null) {
            return false;
        }

        return $this->canInSchool($actor, $capability, $school);
    }

    public function forgetCache(User $actor, ?School $school = null): void
    {
        Cache::forget("platform:capabilities:user:{$actor->id}");
        if ($school !== null) {
            Cache::forget("school:{$school->id}:grants:user:{$actor->id}");
            Cache::forget("school:{$school->id}:capabilities:user:{$actor->id}"); // the pre-ORG.7A key
        }
    }
}
