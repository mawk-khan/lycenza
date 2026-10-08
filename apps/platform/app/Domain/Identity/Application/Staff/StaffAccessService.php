<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\SchoolAccessLock;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Authorization\SchoolAdministrators;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.12B (ADR 0059 owner amendment): a School's staff access
 * lifecycle after acceptance -- off-boarding (suspend), explicit
 * reactivation, and granting / revoking one School role.
 *
 * - Off-boarding = ACTIVE membership -> SUSPENDED membership plus the
 *   revocation of every active School role grant on it. The User, their
 *   password, MFA, other Schools' memberships, platform and Group authority
 *   and any Employee record are never touched; no global credential bump.
 *   `suspended` is the existing kill switch: every request re-checks an
 *   active membership, and the target's capability cache is forgotten.
 * - Reactivation = SUSPENDED -> ACTIVE with explicitly chosen roles as NEW
 *   grant rows; a grant revoked earlier never silently returns.
 * - A role grant is never reactivated in place or deleted; revocation keeps
 *   the row as history.
 *
 * Every operation: fresh capabilities (StaffRoleCatalog), the School
 * operational (SchoolOperationalGuard), never the actor's own membership, a
 * staff membership only (one that has held a School role -- a Guardian's
 * membership is not managed here), and then the LAST QUALIFYING
 * ADMINISTRATOR invariant: nothing may leave the active School with zero
 * qualifying administrators (App\Support\Authorization\SchoolAdministrators).
 *
 * POR.1 (ADR 0059 amendment, ADR 0070 §9.4): "staff" means a membership
 * that holds -- or held -- a `school`-scope role. A `guardian`-scope grant
 * never makes a membership staff, and staff code never grants, revokes or
 * lists it. Off-boarding a membership that is ALSO a live Guardian (an
 * active `guardian` grant and a resolving ActingGuardian -- a dual staff +
 * Guardian person) revokes the staff roles only
 * (`staff_offboarded`) and leaves the membership active, so the Guardian
 * identity survives; reactivation of such a membership re-grants staff roles
 * without a status change.
 *
 * Concurrency: every access-management transaction first takes one
 * transaction-scoped advisory lock per School, so two administrators racing
 * to remove each other's authority serialize; the invariant is evaluated
 * AFTER the change inside the transaction (never a pre-transaction count),
 * so exactly one of them can succeed if the other would leave none.
 */
final class StaffAccessService
{
    public const SUSPENDED = 'school.membership.suspended';

    public const REACTIVATED = 'school.membership.reactivated';

    public const ROLE_ASSIGNED = 'school.membership.role_assigned';

    public const ROLE_REVOKED = 'school.membership.role_revoked';

    /** POR.1: staff authority removed from a membership that keeps its Guardian identity. */
    public const STAFF_OFFBOARDED = 'school.membership.staff_offboarded';

    public function __construct(
        private readonly StaffRoleCatalog $roles,
        private readonly SchoolAdministrators $administrators,
        private readonly SchoolOperationalGuard $guard,
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly ActingGuardianResolver $guardians,
    ) {}

    /**
     * @throws StaffAccountException
     */
    public function suspend(School $school, User $actor, string $membershipId): void
    {
        $this->run($school, $actor, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId);

            if ($membership->status !== SchoolMembership::STATUS_ACTIVE) {
                throw new StaffAccountException('not_active');
            }

            // POR.1 (ADR 0059 amendment): a dual staff + Guardian membership loses
            // its staff roles only; the membership and its Guardian identity stay.
            if ($this->hasGuardianIdentity($membership)) {
                $revokedKeys = $this->revokeAll($school, $actor, $membership, MembershipRoleAssignment::REASON_STAFF_OFFBOARDED);

                if ($revokedKeys === []) {
                    throw new StaffAccountException('not_active');
                }

                $this->audit->school($school, self::STAFF_OFFBOARDED, actor: $actor, subject: $membership, metadata: [
                    'schoolMembershipId' => $membership->id,
                    'userId' => $membership->user_id,
                    'revokedRoleKeys' => $revokedKeys,
                    'guardianIdentityRetained' => true,
                ]);

                return $membership->user;
            }

            $membership->update(['status' => SchoolMembership::STATUS_SUSPENDED]);
            $revokedKeys = $this->revokeAll($school, $actor, $membership, MembershipRoleAssignment::REASON_MEMBERSHIP_SUSPENDED);

            $this->audit->school($school, self::SUSPENDED, actor: $actor, subject: $membership, metadata: [
                'schoolMembershipId' => $membership->id,
                'userId' => $membership->user_id,
                'revokedRoleKeys' => $revokedKeys,
            ]);

            return $membership->user;
        });
    }

    /**
     * @param  list<mixed>  $roleKeys
     *
     * @throws StaffAccountException
     */
    public function reactivate(School $school, User $actor, string $membershipId, array $roleKeys): void
    {
        $roles = $this->roles->grantable($actor, $school, $roleKeys);

        $this->run($school, $actor, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $roles): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId);

            // POR.1: a dual staff + Guardian membership off-boarded from staff
            // stays ACTIVE (its Guardian identity kept); it is "suspended" from
            // staff while it holds no active staff grant.
            $staffOffboarded = $membership->status === SchoolMembership::STATUS_ACTIVE
                && $this->hasGuardianIdentity($membership)
                && ! MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->staff()->active()->exists();

            if ($membership->status !== SchoolMembership::STATUS_SUSPENDED && ! $staffOffboarded) {
                throw new StaffAccountException('not_suspended');
            }

            $user = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();

            if ($user->isDisabled() || ! $user->hasLocalCredential()) {
                throw new StaffAccountException('account_unavailable');
            }

            // A grant left active on a suspended membership (possible only in
            // data written before off-boarding revoked grants) never silently
            // returns: it is revoked, and only the chosen roles are granted.
            $this->revokeAll($school, $actor, $membership, MembershipRoleAssignment::REASON_REACTIVATION_RESET);
            $membership->update(['status' => SchoolMembership::STATUS_ACTIVE]);

            foreach ($roles as $role) {
                $this->grant($school, $actor, $membership, $role);
            }

            $this->audit->school($school, self::REACTIVATED, actor: $actor, subject: $membership, metadata: [
                'schoolMembershipId' => $membership->id,
                'userId' => $membership->user_id,
                'roleKeys' => array_map(fn (Role $role) => $role->key, $roles),
            ]);

            return $user;
        });
    }

    /**
     * @throws StaffAccountException
     */
    public function grantRole(School $school, User $actor, string $membershipId, string $roleKey): void
    {
        [$role] = $this->roles->grantable($actor, $school, [$roleKey]);

        $this->run($school, $actor, [StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $role): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId);

            if ($membership->status !== SchoolMembership::STATUS_ACTIVE) {
                throw new StaffAccountException('not_active');
            }

            $already = MembershipRoleAssignment::query()
                ->where('school_membership_id', $membership->id)
                ->where('role_id', $role->id)
                ->active()
                ->exists();

            if ($already) {
                throw new StaffAccountException('role_already_granted');
            }

            $this->grant($school, $actor, $membership, $role);

            return $membership->user;
        });
    }

    /**
     * @throws StaffAccountException
     */
    public function revokeRole(School $school, User $actor, string $membershipId, string $roleKey): void
    {
        $this->run($school, $actor, [StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $roleKey): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId);

            $grant = MembershipRoleAssignment::query()
                ->where('school_membership_id', $membership->id)
                ->whereIn('role_id', Role::query()->where('key', $roleKey)->where('scope', 'school')->select('id'))
                ->active()
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                throw new StaffAccountException('role_not_granted');
            }

            $this->revokeGrant($school, $actor, $membership, $grant, MembershipRoleAssignment::REASON_REVOKED);

            return $membership->user;
        });
    }

    /**
     * The shared transaction: advisory lock per School -> School operational
     * (FOR SHARE) -> fresh authorization -> the change -> the last-qualifying-
     * administrator invariant -> commit; the target's capability cache is
     * forgotten inside and again after commit.
     *
     * @param  list<string>  $capabilities
     * @param  callable(): User  $change  returns the target User
     *
     * @throws StaffAccountException
     */
    private function run(School $school, User $actor, array $capabilities, callable $change): void
    {
        $target = DB::transaction(function () use ($school, $actor, $capabilities, $change): User {
            SchoolAccessLock::hold($school->id);

            if (! $this->guard->holdOperational($school->id)) {
                throw new StaffAccountException('school_not_operational');
            }

            $capabilities === [StaffRoleCatalog::ROLES]
                ? $this->roles->requireRoleManager($actor, $school)
                : $this->roles->requireIssuer($actor, $school);

            return $this->context->withSchool($school, function () use ($school, $change): User {
                $target = $change();
                $this->capabilities->forgetCache($target, $school);

                if ($this->administrators->qualifying($school)->isEmpty()) {
                    throw new StaffAccountException('last_administrator');
                }

                return $target;
            });
        });

        DB::afterCommit(fn () => $this->capabilities->forgetCache($target, $school));
        $this->capabilities->forgetCache($target, $school);
    }

    /**
     * A staff membership of THIS School, row-locked; never the actor's own.
     *
     * @throws StaffAccountException
     */
    private function lockStaffMembership(School $school, User $actor, string $membershipId): SchoolMembership
    {
        $membership = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->whereKey($membershipId)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw new StaffAccountException('not_found');
        }

        if ($membership->user_id === $actor->id) {
            throw new StaffAccountException('self_administration');
        }

        // POR.1: ever held a `school`-scope role (history counts) -- a
        // Guardian-only membership, whose only grant is `guardian`-scope, is not staff.
        $isStaff = MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->staff()->exists();

        if (! $isStaff) {
            throw new StaffAccountException('not_staff');
        }

        return $membership;
    }

    private function grant(School $school, User $actor, SchoolMembership $membership, Role $role): void
    {
        $grant = MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
        ]);

        $this->audit->school($school, self::ROLE_ASSIGNED, actor: $actor, subject: $grant, metadata: [
            'schoolMembershipId' => $membership->id,
            'roleKey' => $role->key,
        ]);
    }

    /**
     * A LIVE Guardian identity: an active `guardian`-scope grant (which the
     * database allows only beside an active Guardian link, and which only
     * invitation acceptance creates) AND a resolving ActingGuardian (active
     * persona, an eligible relationship) -- never merely an account link an
     * administrator attached, which would let anyone shield their own
     * membership from staff off-boarding.
     */
    private function hasGuardianIdentity(SchoolMembership $membership): bool
    {
        $hasPortalGrant = MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)
            ->guardianPortal()
            ->active()
            ->exists();

        if (! $hasPortalGrant) {
            return false;
        }

        $user = User::query()->find($membership->user_id);

        return $user !== null && $this->guardians->resolve($user, $membership->school) !== null;
    }

    /**
     * The membership's active STAFF grants only -- a `guardian`-scope grant is
     * Guardian lifecycle, never revoked here (POR.1).
     *
     * @return list<string> the revoked role keys
     */
    private function revokeAll(School $school, User $actor, SchoolMembership $membership, string $reason): array
    {
        $grants = MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)
            ->staff()
            ->active()
            ->with('role')
            ->lockForUpdate()
            ->get();

        $keys = [];
        foreach ($grants as $grant) {
            $this->revokeGrant($school, $actor, $membership, $grant, $reason);
            $keys[] = $grant->role->key;
        }

        sort($keys);

        return $keys;
    }

    private function revokeGrant(School $school, User $actor, SchoolMembership $membership, MembershipRoleAssignment $grant, string $reason): void
    {
        $grant->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revocation_reason' => $reason,
        ])->save();

        $this->audit->school($school, self::ROLE_REVOKED, actor: $actor, subject: $grant, metadata: [
            'schoolMembershipId' => $membership->id,
            'roleKey' => $grant->role?->key,
            'reason' => $reason,
        ]);
    }
}
