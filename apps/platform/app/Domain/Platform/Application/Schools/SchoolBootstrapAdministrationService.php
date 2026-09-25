<?php

namespace App\Domain\Platform\Application\Schools;

use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0N.9 (ADR 0047 sections 4, 6): the ONE platform path that writes
 * School memberships -- the bootstrap School Administrator of a School
 * that is still `provisioning`, and nothing else. The relationship is a
 * real, ordinary `school_memberships` row plus an ordinary `school_admin`
 * assignment, exactly what a seeded School Admin has: no flag, no hidden
 * membership, no elevation, no platform-to-School role mapping, never for
 * the acting operator.
 *
 * The path is open only while the School's status is `provisioning`,
 * checked under a lock on the School row. The database never lets a
 * School return to `provisioning` (trg_schools_status_transition), so it
 * closes for good at first activation -- even after a suspension and
 * resume, or if the School later loses every administrator (that is a
 * future break-glass decision, not this path). There is no platform
 * membership administration beyond it (D13, v1).
 */
class SchoolBootstrapAdministrationService
{
    /** The ordinary system School role the bootstrap administrator receives. */
    public const ROLE = 'school_admin';

    public function __construct(
        private readonly SchoolLifecycleAuthority $authority,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Gives $target an active membership with the School Admin role in
     * $school (creating or reactivating their one membership row). Called
     * inside the caller's transaction, with the School row locked and
     * verified `provisioning`.
     */
    public function establish(School $school, User $target, User $actor): SchoolMembership
    {
        $role = Role::query()->where('key', self::ROLE)->where('scope', 'school')->firstOrFail();

        $membership = $this->context->withSchool($school, function () use ($school, $target, $actor, $role): SchoolMembership {
            $membership = SchoolMembership::query()
                ->where('user_id', $target->id)
                ->where('school_id', $school->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                $membership = SchoolMembership::query()->create([
                    'user_id' => $target->id,
                    'school_id' => $school->id,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            } elseif (! $membership->isActive()) {
                $membership->update(['status' => 'active', 'joined_at' => $membership->joined_at ?? now()]);
            }

            $hasRole = MembershipRoleAssignment::query()
                ->where('school_membership_id', $membership->id)
                ->where('role_id', $role->id)
                ->exists();

            if (! $hasRole) {
                MembershipRoleAssignment::query()->create([
                    'school_id' => $school->id,
                    'school_membership_id' => $membership->id,
                    'role_id' => $role->id,
                    'assigned_by_user_id' => $actor->id,
                    'assigned_at' => now(),
                ]);
            }

            return $membership;
        });

        $this->authority->forgetCapabilities($target, $school);

        return $membership;
    }

    /**
     * The current bootstrap administrator(s) of a `provisioning` School: its
     * active memberships. While provisioning, no other path can create one
     * (the School accepts no web, API or invitation access).
     *
     * @return list<SchoolMembership>
     */
    public function currentAdministrators(School $school): array
    {
        return SchoolMembership::query()->active()
            ->where('school_id', $school->id)
            ->with('user')
            ->orderBy('joined_at')->orderBy('id')
            ->get()->all();
    }

    /**
     * Before first activation only: ends the current bootstrap membership
     * (status `suspended` -- kept, with its role assignment, as history;
     * nothing deleted) and establishes $identifier's account instead.
     */
    public function replace(Request $request, User $actor, School $school, mixed $identifier, mixed $confirmed, mixed $code): SchoolMembership
    {
        $operation = SchoolLifecycleOperation::ReplaceBootstrapAdmin;
        $this->authority->authorize($request, $actor, $operation, $school);

        if (! $school->fresh()?->isProvisioning()) {
            $this->authority->deny($request, $actor, $operation, $school, 'bootstrap_closed', 409, 'school', 'The bootstrap administrator can only be changed before the School is first activated.');
        }

        $target = $this->authority->bootstrapTarget($request, $actor, $operation, $school, $identifier);

        if ($this->isActiveMember($school, $target)) {
            $this->authority->deny($request, $actor, $operation, $school, 'bootstrap_target_conflict', 422, 'admin', 'That account is already this School\'s administrator.');
        }

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        [$membership, $failure] = DB::transaction(function () use ($school, $target, $actor): array {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isProvisioning()) {
                return [null, 'bootstrap_closed'];
            }

            if ($this->isActiveMember($locked, $target)) {
                return [null, 'bootstrap_target_conflict'];
            }

            $previous = $this->currentAdministrators($locked);

            foreach ($previous as $old) {
                $old->update(['status' => 'suspended']);
                $this->authority->forgetCapabilities($old->user, $locked);
            }

            $membership = $this->establish($locked, $target, $actor);

            $this->audit->platform(SchoolLifecycleAudit::BOOTSTRAP_ADMIN_REPLACED, actor: $actor, subject: $locked, metadata: [
                'previous_user_id' => $previous[0]->user_id ?? null,
                'user_id' => $target->id,
                'membership_id' => $membership->id,
            ]);

            return [$membership, null];
        });

        if ($failure !== null) {
            $failure === 'bootstrap_closed'
                ? $this->authority->deny($request, $actor, $operation, $school, $failure, 409, 'school', 'The bootstrap administrator can only be changed before the School is first activated.')
                : $this->authority->deny($request, $actor, $operation, $school, $failure, 422, 'admin', 'That account is already this School\'s administrator.');
        }

        return $membership;
    }

    private function isActiveMember(School $school, User $user): bool
    {
        return SchoolMembership::query()->active()
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
