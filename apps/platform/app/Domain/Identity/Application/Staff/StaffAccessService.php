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
 *
 * SR.2 (ADR 0071 §6.2, §10, §14): WHO may grant or revoke WHICH role is
 * RoleGrantAuthority's decision, taken INSIDE the transaction after the
 * locks (School access lock -> School FOR SHARE -> target membership FOR
 * UPDATE -> its grants -> the issuer's membership and active grants FOR
 * SHARE -> the User FOR UPDATE on reactivation), never on a pre-transaction
 * read. Grant (grantRole, reactivation): held or covered by a held grant
 * right; the role active and non-empty. Revoke one role: the issuer must be
 * able to grant it now (a retired or emptied role stays revocable).
 * Off-boarding (suspend) and the reactivation reset are NOT coverage-gated
 * (§10.3: the emergency path always removes every staff role). A refused
 * decision rolls the mutation back and is then audited once
 * (`school.membership.role_grant_refused`, RoleGrantRefusalAudit); the SR.1
 * database grantor trigger stays the final backstop.
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
        private readonly RoleGrantAuthority $authority,
        private readonly RoleGrantRefusalAudit $refusals,
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
        $roles = $this->roles->resolve($roleKeys);
        $refusal = ['stage' => 'reactivation', 'schoolMembershipId' => $membershipId, 'roleKey' => $roles[0]->key];

        $this->run($school, $actor, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $roles, $refusal): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId, $refusal);

            // POR.1: a dual staff + Guardian membership off-boarded from staff
            // stays ACTIVE (its Guardian identity kept); it is "suspended" from
            // staff while it holds no active staff grant.
            $staffOffboarded = $membership->status === SchoolMembership::STATUS_ACTIVE
                && $this->hasGuardianIdentity($membership)
                && ! MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->staff()->active()->exists();

            if ($membership->status !== SchoolMembership::STATUS_SUSPENDED && ! $staffOffboarded) {
                throw new StaffAccountException('not_suspended');
            }

            // SR.2: every chosen role decided under the locks, before any change.
            $decisions = [];
            foreach ($roles as $role) {
                $decisions[$role->id] = $this->decideGrant($school, $actor, $membership, $role, 'reactivation');
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
                $this->grant($school, $actor, $membership, $role, $decisions[$role->id], 'reactivation');
            }

            $this->audit->school($school, self::REACTIVATED, actor: $actor, subject: $membership, metadata: [
                'schoolMembershipId' => $membership->id,
                'userId' => $membership->user_id,
                'roleKeys' => array_map(fn (Role $role) => $role->key, $roles),
            ]);

            return $user;
        }, $refusal);
    }

    /**
     * @throws StaffAccountException
     */
    public function grantRole(School $school, User $actor, string $membershipId, string $roleKey): void
    {
        [$role] = $this->roles->resolve([$roleKey]);
        $refusal = ['stage' => 'grant', 'schoolMembershipId' => $membershipId, 'roleKey' => $role->key];

        $this->run($school, $actor, [StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $role, $refusal): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId, $refusal);

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

            $decision = $this->decideGrant($school, $actor, $membership, $role, 'grant');
            $this->grant($school, $actor, $membership, $role, $decision, 'grant');

            return $membership->user;
        }, $refusal);
    }

    /**
     * @throws StaffAccountException
     */
    public function revokeRole(School $school, User $actor, string $membershipId, string $roleKey): void
    {
        $refusal = ['stage' => 'revoke', 'schoolMembershipId' => $membershipId, 'roleKey' => $roleKey];

        $this->run($school, $actor, [StaffRoleCatalog::ROLES], function () use ($school, $actor, $membershipId, $roleKey, $refusal): User {
            $membership = $this->lockStaffMembership($school, $actor, $membershipId, $refusal);

            $grant = MembershipRoleAssignment::query()
                ->where('school_membership_id', $membership->id)
                ->whereIn('role_id', Role::query()->where('key', $roleKey)->where('scope', 'school')->select('id'))
                ->active()
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                throw new StaffAccountException('role_not_granted');
            }

            // SR.2 (ADR 0071 §10.2): only a role the issuer could grant now.
            $role = Role::query()->findOrFail($grant->role_id);
            $decision = $this->authority->forRevoke($actor, $school, $role, $membership->user_id, lock: true);
            if (! $decision->allowed()) {
                throw StaffAccountException::refusedGrant($decision, $refusal);
            }

            $this->revokeGrant($school, $actor, $membership, $grant, MembershipRoleAssignment::REASON_REVOKED);

            return $membership->user;
        }, $refusal);
    }

    /**
     * The shared transaction: advisory lock per School -> School operational
     * (FOR SHARE) -> fresh authorization -> the change -> the last-qualifying-
     * administrator invariant -> commit; the target's capability cache is
     * forgotten inside and again after commit.
     *
     * SR.2: for a ROLE operation ($refusal set: grant, revoke, reactivation)
     * an authorization refusal inside the transaction is a refused role-grant
     * decision, audited once after the rollback.
     *
     * @param  list<string>  $capabilities
     * @param  callable(): User  $change  returns the target User
     * @param  array<string, mixed>|null  $refusal  stage / schoolMembershipId / roleKey of a role operation
     *
     * @throws StaffAccountException
     */
    private function run(School $school, User $actor, array $capabilities, callable $change, ?array $refusal = null): void
    {
        try {
            $target = $this->transact($school, $actor, $capabilities, $change, $refusal);
        } catch (StaffAccountException $e) {
            $this->refusals->recordAfterRollback($school, $actor, $e);

            throw $e;
        }

        DB::afterCommit(fn () => $this->capabilities->forgetCache($target, $school));
        $this->capabilities->forgetCache($target, $school);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  callable(): User  $change
     * @param  array<string, mixed>|null  $refusal
     */
    private function transact(School $school, User $actor, array $capabilities, callable $change, ?array $refusal): User
    {
        return DB::transaction(function () use ($school, $actor, $capabilities, $change, $refusal): User {
            SchoolAccessLock::hold($school->id);

            if (! $this->guard->holdOperational($school->id)) {
                throw new StaffAccountException('school_not_operational');
            }

            try {
                $capabilities === [StaffRoleCatalog::ROLES]
                    ? $this->roles->requireRoleManager($actor, $school)
                    : $this->roles->requireIssuer($actor, $school);
            } catch (StaffAccountException $e) {
                if ($refusal === null) {
                    throw $e;
                }

                $reason = $this->authority->held($actor, $school) === null ? 'inactive_issuer' : 'not_role_manager';

                throw StaffAccountException::refusedGrant(RoleGrantDecision::refuse($reason), $refusal);
            }

            return $this->context->withSchool($school, function () use ($school, $change): User {
                $target = $change();
                $this->capabilities->forgetCache($target, $school);

                if ($this->administrators->qualifying($school)->isEmpty()) {
                    throw new StaffAccountException('last_administrator');
                }

                return $target;
            });
        });
    }

    /**
     * A staff membership of THIS School, row-locked; never the actor's own
     * (for a role operation, an audited self-administration refusal).
     *
     * @param  array<string, mixed>|null  $refusal
     *
     * @throws StaffAccountException
     */
    private function lockStaffMembership(School $school, User $actor, string $membershipId, ?array $refusal = null): SchoolMembership
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
            throw $refusal === null
                ? new StaffAccountException('self_administration')
                : StaffAccountException::refusedGrant(RoleGrantDecision::refuse('self_administration'), $refusal);
        }

        // POR.1: ever held a `school`-scope role (history counts) -- a
        // Guardian-only membership, whose only grant is `guardian`-scope, is not staff.
        $isStaff = MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->staff()->exists();

        if (! $isStaff) {
            throw new StaffAccountException('not_staff');
        }

        return $membership;
    }

    /**
     * SR.2: the grant authority decided under the locks; refused -> thrown
     * (audited after the rollback).
     *
     * @throws StaffAccountException
     */
    private function decideGrant(School $school, User $actor, SchoolMembership $membership, Role $role, string $stage): RoleGrantDecision
    {
        $decision = $this->authority->forGrant($actor, $school, $role, $membership->user_id, lock: true);

        if (! $decision->allowed()) {
            throw StaffAccountException::refusedGrant($decision, ['stage' => $stage, 'schoolMembershipId' => $membership->id, 'roleKey' => $role->key]);
        }

        return $decision;
    }

    private function grant(School $school, User $actor, SchoolMembership $membership, Role $role, RoleGrantDecision $decision, string $stage): void
    {
        $grant = RoleGrantAuthority::backstopped(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
        ]), ['stage' => $stage, 'schoolMembershipId' => $membership->id, 'roleKey' => $role->key]);

        // SR.2 (ADR 0071 §13): a grant made through a grant right records the
        // rights used and the classes they covered; an ordinary grant stays lightweight.
        $this->audit->school($school, self::ROLE_ASSIGNED, actor: $actor, subject: $grant, metadata: [
            'schoolMembershipId' => $membership->id,
            'roleKey' => $role->key,
            ...$decision->grantMetadata(),
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
