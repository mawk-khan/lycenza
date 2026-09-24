<?php

namespace App\Support\Authorization;

use App\Models\GroupRoleAssignment;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, string>
     */
    public function platformCapabilities(User $actor): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        return Cache::remember(
            "platform:capabilities:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            fn () => $actor->platformRoleAssignments()
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

        return Cache::remember(
            "school:{$school->id}:capabilities:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($actor, $school) {
                $membership = SchoolMembership::query()
                    ->where('user_id', $actor->id)
                    ->where('school_id', $school->id)
                    ->active()
                    ->first();

                if ($membership === null) {
                    return [];
                }

                // MembershipRoleAssignment is RLS-protected: read it
                // authoritatively for $school regardless of whatever
                // ambient TenantContext the caller currently has. See
                // TenantContext::withSchool()'s docblock.
                return $this->context->withSchool(
                    $school,
                    fn () => MembershipRoleAssignment::query()
                        ->where('school_membership_id', $membership->id)
                        ->with('role.capabilities')
                        ->get()
                        ->flatMap(fn ($assignment) => $assignment->role->capabilities->pluck('key'))
                        ->unique()
                        ->values()
                        ->all(),
                );
            },
        );
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
        if ($actor->isDisabled()) {
            return [];
        }

        return GroupRoleAssignment::query()
            ->active()
            ->where('group_role_assignments.user_id', $actor->id)
            ->where('group_role_assignments.school_group_id', $group->id)
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

    /**
     * The active Groups in which the actor holds $capability -- only their
     * own Groups, never a directory.
     *
     * @return Collection<int, SchoolGroup>
     */
    public function groupsWith(User $actor, string $capability): Collection
    {
        if ($actor->isDisabled()) {
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

    public function canPlatform(User $actor, string $capability): bool
    {
        return in_array($capability, $this->platformCapabilities($actor), true);
    }

    public function canInSchool(User $actor, string $capability, School $school): bool
    {
        return in_array($capability, $this->schoolCapabilities($actor, $school), true);
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
            Cache::forget("school:{$school->id}:capabilities:user:{$actor->id}");
        }
    }
}
