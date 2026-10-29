<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\GroupRoleAssignment;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ORG.12E (ADR 0080 §10, OD-12.13/OD-12.14): the ONE resolver of Network
 * governance authority in a School Group -- bounded to the Group layer.
 *
 * - It answers only Group-governance questions (view scope, structure
 *   management, operator management). It never returns, implies or reads a
 *   School capability, membership or tenant table, and nothing School-side
 *   calls it (architecture guard).
 * - Platform authority (`platform.school_groups.*`, ADR 0045 §5) still
 *   governs every Group; a whole-Group `network_manager` grant adds the same
 *   governance for its ONE Group (never School addition, never appointments
 *   of management -- database trigger and the services).
 * - Read scope comes from grants carrying `group.network.view` (whole Group,
 *   one Region or one Zone) or the legacy `group.schools.view` (always whole
 *   Group). Management needs an UNSCOPED grant; a scoped grant can never
 *   carry management (database trigger).
 * - Uncached, like every Group capability (ADR 0045 §10). Disabled accounts
 *   and bearer tokens hold nothing.
 */
class GroupGovernanceAccess
{
    public const VIEW = 'group.network.view';

    public const MANAGE = 'group.network.manage';

    public const MANAGE_OPERATORS = 'group.network.operators.manage';

    /** The legacy Group Admin view: always whole-Group. */
    public const LEGACY_VIEW = 'group.schools.view';

    public function __construct(private readonly CapabilityResolver $capabilities) {}

    /** The actor's Network-governance read scope in $group, or null when they have none. */
    public function viewScope(User $actor, SchoolGroup $group): ?GroupGovernanceScope
    {
        if ($this->excluded($actor) || ! $group->isActive()) {
            return null;
        }

        $grants = GroupRoleAssignment::query()->active()
            ->where('group_role_assignments.user_id', $actor->id)
            ->where('group_role_assignments.school_group_id', $group->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('role_capabilities')
                ->whereColumn('role_capabilities.role_id', 'group_role_assignments.role_id')
                ->whereIn('role_capabilities.capability_key', [self::VIEW, self::LEGACY_VIEW]))
            ->get(['group_role_assignments.region_id', 'group_role_assignments.zone_id']);

        if ($grants->isEmpty()) {
            return null;
        }
        if ($grants->contains(fn (GroupRoleAssignment $g): bool => $g->region_id === null && $g->zone_id === null)) {
            return GroupGovernanceScope::whole();
        }

        return new GroupGovernanceScope(
            false,
            $grants->pluck('region_id')->filter()->unique()->sort()->values()->all(),
            $grants->pluck('zone_id')->filter()->unique()->sort()->values()->all(),
        );
    }

    /** Platform governance of every Group, or a whole-Group Network Manager of this one (display/floor check). */
    public function mayManage(User $actor, SchoolGroup $group): bool
    {
        return $this->capabilities->canPlatform($actor, SchoolGroupGovernanceService::MANAGE)
            || $this->holdsWholeGroup($actor, $group, self::MANAGE, lock: false);
    }

    public function mayManageOperators(User $actor, SchoolGroup $group): bool
    {
        return $this->capabilities->canPlatform($actor, SchoolGroupGovernanceService::MANAGE_GRANTS)
            || $this->holdsWholeGroup($actor, $group, self::MANAGE_OPERATORS, lock: false);
    }

    /** Is the platform (not a Network Manager) the authority? Decides audit provenance and platform-only steps. */
    public function isPlatformManager(User $actor): bool
    {
        return $this->capabilities->canPlatform($actor, SchoolGroupGovernanceService::MANAGE);
    }

    /** In-transaction re-read of management authority, ordered against revocation (FOR SHARE). */
    public function requireManageNow(User $actor, SchoolGroup $group): string
    {
        return $this->requireNow($actor, $group, SchoolGroupGovernanceService::MANAGE, self::MANAGE);
    }

    public function requireOperatorsManageNow(User $actor, SchoolGroup $group): string
    {
        return $this->requireNow($actor, $group, SchoolGroupGovernanceService::MANAGE_GRANTS, self::MANAGE_OPERATORS);
    }

    /** @return string 'platform' or 'network' -- the authority that decided */
    private function requireNow(User $actor, SchoolGroup $group, string $platformCapability, string $groupCapability): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GroupGovernanceAccess must re-read authority inside the decision transaction.');
        }
        if ($this->capabilities->holdsPlatformNow($actor, $platformCapability)) {
            return 'platform';
        }
        if ($this->holdsWholeGroup($actor, $group, $groupCapability, lock: true)) {
            return 'network';
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    private function holdsWholeGroup(User $actor, SchoolGroup $group, string $capability, bool $lock): bool
    {
        if (CapabilityResolver::isBearerAuthenticated($actor)) {
            return false;
        }
        $user = User::query()->whereKey($actor->id)->where('is_disabled', false);
        if (! ($lock ? $user->sharedLock() : $user)->exists()) {
            return false;
        }
        $groupActive = SchoolGroup::query()->whereKey($group->id)->where('status', SchoolGroup::STATUS_ACTIVE);
        if (! ($lock ? $groupActive->sharedLock() : $groupActive)->exists()) {
            return false;
        }

        $grants = GroupRoleAssignment::query()->active()
            ->where('user_id', $actor->id)
            ->where('school_group_id', $group->id)
            ->whereNull('region_id')->whereNull('zone_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('role_capabilities')
                ->whereColumn('role_capabilities.role_id', 'group_role_assignments.role_id')
                ->where('role_capabilities.capability_key', $capability));

        return ($lock ? $grants->sharedLock() : $grants)->exists();
    }

    private function excluded(User $actor): bool
    {
        return $actor->isDisabled() || CapabilityResolver::isBearerAuthenticated($actor);
    }
}
