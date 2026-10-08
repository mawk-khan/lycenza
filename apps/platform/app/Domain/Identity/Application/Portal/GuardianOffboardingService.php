<?php

namespace App\Domain\Identity\Application\Portal;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\SchoolAccessLock;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * POR.1 (ADR 0070 §9.2): ending ONE Guardian's portal access in ONE School,
 * deterministically and without any staff-role lifecycle behaviour.
 *
 * Needs `guardians.manage` AND `school.members.manage` (fresh) plus a fresh
 * MFA code (the controller, like staff off-boarding). One transaction:
 * School access lock -> School operational (FOR SHARE) -> the Guardian's
 * active account link (FOR UPDATE) -> its membership (FOR UPDATE) -> then:
 *
 * - revoke the `guardian`-scope grant (history kept, reason
 *   `guardian_link_revoked`);
 * - revoke the account link (the database refuses the reverse order);
 * - revoke any pending Guardian account invitation (no re-activation);
 * - suspend the membership ONLY when it holds no active staff (`school`-scope)
 *   grant -- a staff identity in the same membership is never touched.
 *
 * Nothing is deleted; the User, other Schools and staff authority are
 * untouched. The ActingGuardian of that membership fails on the very next
 * request (the link is gone), whatever any cache holds; the capability cache
 * is forgotten anyway. Never the actor's own membership.
 */
final class GuardianOffboardingService
{
    public const OFFBOARDED = 'guardian.portal_offboarded';

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $guard,
        private readonly GuardianPortalRoleGrants $grants,
        private readonly AuditRecorder $audit,
        private readonly AccountInvitationService $invitations,
    ) {}

    /** @throws GuardianOffboardingException */
    public function offboard(School $school, User $actor, Guardian $guardian): void
    {
        $target = DB::transaction(function () use ($school, $actor, $guardian): User {
            SchoolAccessLock::hold($school->id);

            if (! $this->guard->holdOperational($school->id)) {
                throw new GuardianOffboardingException('school_not_operational');
            }

            $this->capabilities->forgetCache($actor, $school);
            foreach (['guardians.manage', 'school.members.manage'] as $capability) {
                if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                    throw new GuardianOffboardingException('not_authorized');
                }
            }

            return $this->context->withSchool($school, function () use ($school, $actor, $guardian): User {
                $link = StudentGuardianAccountLink::query()
                    ->where('school_id', $school->id)
                    ->where('guardian_id', $guardian->id)
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($link === null) {
                    throw new GuardianOffboardingException('no_active_link');
                }

                $membership = SchoolMembership::query()
                    ->where('school_id', $school->id)
                    ->whereKey($link->school_membership_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($membership->user_id === $actor->id) {
                    throw new GuardianOffboardingException('self_administration');
                }

                $revokedGrants = $this->grants->revokeAll($school, $membership, $actor, MembershipRoleAssignment::REASON_GUARDIAN_LINK_REVOKED);

                $link->update(['status' => 'revoked', 'unlinked_by_user_id' => $actor->id, 'unlinked_at' => now()]);
                // A still-pending invitation could otherwise re-create the link
                // and the grant after off-boarding: revoke it in this transaction.
                $this->invitations->revoke($school, $guardian, $actor);
                $this->audit->school($school, 'guardian.account_unlinked', actor: $actor, subject: $link, metadata: [
                    'studentId' => null,
                    'guardianId' => $guardian->id,
                    'schoolMembershipId' => $membership->id,
                ]);

                $keepsStaff = MembershipRoleAssignment::query()
                    ->where('school_membership_id', $membership->id)
                    ->staff()
                    ->active()
                    ->exists();

                $suspended = false;
                if (! $keepsStaff && $membership->status === SchoolMembership::STATUS_ACTIVE) {
                    $membership->update(['status' => SchoolMembership::STATUS_SUSPENDED]);
                    $suspended = true;
                }

                $this->audit->school($school, self::OFFBOARDED, actor: $actor, subject: $membership, metadata: [
                    'guardianId' => $guardian->id,
                    'schoolMembershipId' => $membership->id,
                    'revokedPortalGrants' => $revokedGrants,
                    'membershipSuspended' => $suspended,
                    'staffIdentityRetained' => $keepsStaff,
                ]);

                $user = User::query()->findOrFail($membership->user_id);
                $this->capabilities->forgetCache($user, $school);

                return $user;
            });
        });

        DB::afterCommit(fn () => $this->capabilities->forgetCache($target, $school));
        $this->capabilities->forgetCache($target, $school);
    }
}
