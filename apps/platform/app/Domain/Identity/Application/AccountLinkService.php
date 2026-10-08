<?php

namespace App\Domain\Identity\Application;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Exceptions\CrossSchoolMembershipLinkException;
use App\Domain\Identity\Application\Exceptions\MembershipAlreadyLinkedException;
use App\Domain\Identity\Application\Exceptions\NoActiveAccountLinkException;
use App\Domain\Identity\Application\Exceptions\PersonaAlreadyLinkedException;
use App\Domain\Identity\Application\Portal\GuardianPortalRoleGrants;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B.2 -- the SOLE write path for linking/unlinking a Student or
 * Guardian domain identity to an existing SchoolMembership. Owned by
 * Identity, not Communications (docs/communication-hub/
 * PHASE-5B-2-STUDENT-GUARDIAN-ACCOUNT-LINK-INAPP.md "Link ownership") --
 * App\Domain\Communications\Application\AnnouncementService only ever
 * READS an active link via activeLinksForGuardians()/
 * activeLinksForStudents(), never creates/mutates one.
 *
 * Never creates a User or SchoolMembership -- both must already exist
 * (root CLAUDE.md non-negotiable: account provisioning is a separate,
 * explicitly out-of-scope concern). A membership's ACTIVE status is
 * NOT required at link time (an `invited` membership may be linked in
 * advance) -- only re-checked at communication-reachability-resolution
 * time, mirroring every other "resolved a moment ago, no longer
 * eligible" re-validation pattern already established in this
 * codebase (StudentAudienceResolver/GuardianAudienceResolver, Phase
 * 5B.1).
 *
 * Idempotent-by-construction (root CLAUDE.md rule 30): the two partial
 * unique indexes (`sgal_one_active_per_student`/`sgal_one_active_per_guardian`/
 * `sgal_one_active_per_membership`) are the authoritative concurrency
 * guarantee -- never a check-then-insert. A race is caught via
 * UniqueConstraintViolationException and translated to a clean domain
 * exception, not left as a raw SQL error.
 */
class AccountLinkService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly GuardianPortalRoleGrants $portalGrants,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function linkStudent(School $school, Student $student, SchoolMembership $membership, User $actor): StudentGuardianAccountLink
    {
        return $this->link($school, $student, null, $membership, $actor, 'student.account_linked');
    }

    public function linkGuardian(School $school, Guardian $guardian, SchoolMembership $membership, User $actor): StudentGuardianAccountLink
    {
        return $this->link($school, null, $guardian, $membership, $actor, 'guardian.account_linked');
    }

    public function unlinkStudent(School $school, Student $student, User $actor): void
    {
        $this->unlink($school, fn () => $this->activeLinkForStudent($student), $actor, 'student.account_unlinked');
    }

    public function unlinkGuardian(School $school, Guardian $guardian, User $actor): void
    {
        $this->unlink($school, fn () => $this->activeLinkForGuardian($guardian), $actor, 'guardian.account_unlinked');
    }

    public function activeLinkForStudent(Student $student): ?StudentGuardianAccountLink
    {
        return $this->context->withSchool($student->school, fn () => StudentGuardianAccountLink::query()
            ->where('student_id', $student->id)
            ->active()
            ->with('membership')
            ->first());
    }

    public function activeLinkForGuardian(Guardian $guardian): ?StudentGuardianAccountLink
    {
        return $this->context->withSchool($guardian->school, fn () => StudentGuardianAccountLink::query()
            ->where('guardian_id', $guardian->id)
            ->active()
            ->with('membership')
            ->first());
    }

    /**
     * Phase 5B.2 §49: ONE batched query for an entire audience chunk --
     * never one query per Guardian, mirroring
     * App\Domain\Communications\Application\AnnouncementService::snapshotRecipients()'s
     * own batching discipline.
     *
     * @param  array<int, string>  $guardianIds
     * @return Collection<string, StudentGuardianAccountLink> guardianId => active link (with membership eager-loaded)
     */
    public function activeLinksForGuardians(School $school, array $guardianIds): Collection
    {
        if ($guardianIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => StudentGuardianAccountLink::query()
            ->whereIn('guardian_id', $guardianIds)
            ->active()
            ->with('membership')
            ->get()
            ->keyBy('guardian_id'));
    }

    /**
     * @param  array<int, string>  $studentIds
     * @return Collection<string, StudentGuardianAccountLink> studentId => active link (with membership eager-loaded)
     */
    public function activeLinksForStudents(School $school, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->context->withSchool($school, fn () => StudentGuardianAccountLink::query()
            ->whereIn('student_id', $studentIds)
            ->active()
            ->with('membership')
            ->get()
            ->keyBy('student_id'));
    }

    private function link(
        School $school,
        ?Student $student,
        ?Guardian $guardian,
        SchoolMembership $membership,
        User $actor,
        string $auditEventType,
    ): StudentGuardianAccountLink {
        // Root CLAUDE.md rule 19: a membership id alone is never
        // authorization to link against it -- re-verified here, not
        // trusted from whatever the caller already believed.
        if ($membership->school_id !== $school->id) {
            throw new CrossSchoolMembershipLinkException;
        }

        return $this->context->withSchool($school, function () use ($school, $student, $guardian, $membership, $actor, $auditEventType) {
            $existing = $student !== null ? $this->activeLinkForStudent($student) : $this->activeLinkForGuardian($guardian);

            if ($existing !== null) {
                throw new PersonaAlreadyLinkedException;
            }

            $membershipAlreadyLinked = StudentGuardianAccountLink::query()
                ->where('school_membership_id', $membership->id)
                ->active()
                ->exists();

            if ($membershipAlreadyLinked) {
                throw new MembershipAlreadyLinkedException;
            }

            try {
                return DB::transaction(function () use ($school, $student, $guardian, $membership, $actor, $auditEventType) {
                    $link = StudentGuardianAccountLink::query()->create([
                        'school_id' => $school->id,
                        'student_id' => $student?->id,
                        'guardian_id' => $guardian?->id,
                        'school_membership_id' => $membership->id,
                        'status' => 'active',
                        'linked_by_user_id' => $actor->id,
                        'linked_at' => now(),
                    ]);

                    $this->audit->school($school, $auditEventType, actor: $actor, subject: $link, metadata: [
                        'studentId' => $student?->id,
                        'guardianId' => $guardian?->id,
                        'schoolMembershipId' => $membership->id,
                    ]);

                    return $link;
                });
            } catch (UniqueConstraintViolationException $e) {
                // The sgal_one_active_per_membership partial unique
                // index is the only remaining way this insert can fail
                // once the persona-level pre-check above passed --
                // translate to the clean domain exception rather than
                // leaking the raw constraint violation.
                if (str_contains($e->getMessage(), 'sgal_one_active_per_membership')) {
                    throw new MembershipAlreadyLinkedException;
                }

                throw new PersonaAlreadyLinkedException;
            }
        });
    }

    private function unlink(School $school, \Closure $findActive, User $actor, string $auditEventType): void
    {
        $this->context->withSchool($school, function () use ($school, $findActive, $actor, $auditEventType): void {
            $link = $findActive();

            if ($link === null) {
                throw new NoActiveAccountLinkException;
            }

            DB::transaction(function () use ($school, $link, $actor, $auditEventType) {
                // POR.1 (ADR 0070 §9.2): ending a Guardian's account link ends
                // their portal authority in this School. Under the School
                // access lock, the `guardian`-scope grant is revoked FIRST --
                // the database refuses ending a link whose membership still
                // holds an active one. A Student link carries no grant.
                if ($link->guardian_id !== null) {
                    SchoolAccessLock::hold($school->id);
                    $link = StudentGuardianAccountLink::query()->whereKey($link->id)->active()->lockForUpdate()->first()
                        ?? throw new NoActiveAccountLinkException;
                    $membership = $link->membership()->lockForUpdate()->firstOrFail();
                    $this->portalGrants->revokeAll($school, $membership, $actor, MembershipRoleAssignment::REASON_GUARDIAN_LINK_REVOKED);
                    $user = User::query()->find($membership->user_id);
                    if ($user !== null) {
                        $this->capabilities->forgetCache($user, $school);
                        DB::afterCommit(fn () => $this->capabilities->forgetCache($user, $school));
                    }
                }

                $link->update([
                    'status' => 'revoked',
                    'unlinked_by_user_id' => $actor->id,
                    'unlinked_at' => now(),
                ]);

                $this->audit->school($link->school, $auditEventType, actor: $actor, subject: $link, metadata: [
                    'studentId' => $link->student_id,
                    'guardianId' => $link->guardian_id,
                    'schoolMembershipId' => $link->school_membership_id,
                ]);
            });
        });
    }
}
