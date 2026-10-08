<?php

namespace App\Domain\Identity\Application\Portal;

use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;

/**
 * POR (ADR 0070 §8.2, §9): the ONLY writer of `guardian`-scope role grants.
 *
 * - grant(): on Guardian account activation, idempotently (an active grant
 *   is reused, never duplicated). The database refuses the insert unless the
 *   membership carries an active Guardian account link.
 * - revokeAll(): before the Guardian account link ends (the database refuses
 *   ending a link whose membership still holds an active grant). History is
 *   kept: a revocation stamps the row once; re-granting inserts a new row.
 *
 * Callers run inside their own transaction, hold the School's access lock,
 * and forget the target's capability cache. Staff code never grants or
 * revokes this role, and nothing checks it by name.
 */
final class GuardianPortalRoleGrants
{
    public const GRANTED = 'guardian.portal_role_granted';

    public const REVOKED = 'guardian.portal_role_revoked';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function grant(School $school, SchoolMembership $membership, User $actor): bool
    {
        $role = Role::query()->where('key', Role::GUARDIAN)->where('scope', Role::SCOPE_GUARDIAN)->firstOrFail();

        $active = MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)
            ->where('role_id', $role->id)
            ->active()
            ->lockForUpdate()
            ->exists();

        if ($active) {
            return false;
        }

        $grant = MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now(),
        ]);

        $this->audit->school($school, self::GRANTED, actor: $actor, subject: $grant, metadata: [
            'schoolMembershipId' => $membership->id,
        ]);

        return true;
    }

    public function revokeAll(School $school, SchoolMembership $membership, User $actor, string $reason): int
    {
        $grants = MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)
            ->guardianPortal()
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($grants as $grant) {
            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->school($school, self::REVOKED, actor: $actor, subject: $grant, metadata: [
                'schoolMembershipId' => $membership->id,
                'reason' => $reason,
            ]);
        }

        return $grants->count();
    }
}
