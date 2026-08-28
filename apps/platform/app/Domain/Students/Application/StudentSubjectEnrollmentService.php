<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\ActiveSubjectEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectEnrollmentException;
use App\Domain\Students\Application\Exceptions\ElectiveGroupConflictException;
use App\Domain\Students\Application\Exceptions\InactiveSubjectOfferingException;
use App\Domain\Students\Application\Exceptions\IncompatibleSubjectOfferingException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingEnrollmentException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1C.1: the ONLY sanctioned write path for a
 * StudentSubjectEnrollment -- creation (`enroll()`), the terminal
 * transitions (`withdraw()`/`cancel()`), and the atomic elective-switch
 * operation (`transfer()`), mirroring
 * App\Domain\Students\Application\StudentEnrollmentService's exact
 * shape one level down (Subject-level rather than Grade/Section-level
 * placement). Deliberately authorization-neutral, matching every other
 * Application service in this codebase -- a future controller must call
 * `authorizeCapability` before ever reaching this service.
 *
 * Never accepts `academic_year_id` as independent caller input -- it is
 * always DERIVED from the caller-supplied SubjectOffering server-side
 * (CLAUDE.md rule 19's principle applied here, mirroring
 * StudentEnrollmentService's identical treatment of
 * academic_year_id/campus_id/grade_level_id).
 */
class StudentSubjectEnrollmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Enrolls a Student into an elective/optional SubjectOffering
     * (`is_required = false`). Rejects a REQUIRED offering outright --
     * see RequiredSubjectOfferingEnrollmentException's docblock -- and
     * rejects an offering that does not match the Student's CURRENT
     * active StudentEnrollment (AcademicYear + GradeLevel + Campus, the
     * academic-compatibility rule, Phase 1C.1 section 11).
     *
     * Phase 1F.2: locks and reloads the target SubjectOffering INSIDE
     * the mutation transaction before reading its `status`/`is_required`/
     * `elective_group_id` -- a stale caller-held `$offering` model (its
     * in-memory attributes) is never trusted for these mutable fields
     * (architecture doc §16A). The row this call resolves as the
     * Student's compatible `StudentEnrollment` -- the SAME row
     * `resolveCompatibleEnrollment()` uses for the academic-compatibility
     * check -- becomes the new row's `student_enrollment_id` placement
     * anchor; `elective_group_id` is snapshotted from the just-locked
     * Offering row. Neither value is ever accepted as caller input.
     */
    public function enroll(Student $student, SubjectOffering $offering, string $startsOn, ?User $actor = null): StudentSubjectEnrollment
    {
        if ($student->school_id !== $offering->school_id) {
            throw new CrossSchoolSubjectEnrollmentException;
        }

        return $this->context->withSchool($student->school, function () use ($student, $offering, $startsOn, $actor) {
            try {
                return DB::transaction(function () use ($student, $offering, $startsOn, $actor) {
                    $lockedOffering = $this->lockOffering($offering, $student->school_id);

                    $this->assertOfferingIsActive($lockedOffering);

                    if ($lockedOffering->is_required) {
                        throw new RequiredSubjectOfferingEnrollmentException;
                    }

                    $studentEnrollment = $this->resolveCompatibleEnrollment($student, $lockedOffering);

                    return $this->createRow($student, $lockedOffering, $studentEnrollment, $startsOn, $actor);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * The Student left this elective before it ran its normal course.
     * Never touches Student.status, StudentEnrollment, or any Guardian
     * relationship -- subject-enrollment lifecycle is independent of
     * every other lifecycle in this domain.
     */
    public function withdraw(StudentSubjectEnrollment $enrollment, string $endedOn, ?User $actor = null): StudentSubjectEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'withdrawn', $endedOn, 'student_subject_enrollment.withdrawn', $actor);
    }

    /**
     * The subject enrollment should not continue as an operational
     * membership -- retained purely as an administrative historical
     * record, never deleted. Mirrors StudentEnrollmentService::cancel()'s
     * identical narrow interpretation.
     */
    public function cancel(StudentSubjectEnrollment $enrollment, string $endedOn, ?User $actor = null): StudentSubjectEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'cancelled', $endedOn, 'student_subject_enrollment.cancelled', $actor);
    }

    /**
     * Switches a Student from one elective SubjectOffering to another
     * (e.g. French -> Spanish) -- an atomic, two-row historical
     * operation (mark the old row `transferred`, create a brand-new
     * `active` row for the target offering), never a column UPDATE on
     * the existing row. Mirrors StudentEnrollmentService::transferPlacement()'s
     * exact shape.
     *
     * Phase 1F.2: enforces elective-group mutual exclusivity on the
     * TARGET side -- the target SubjectOffering is locked and reloaded
     * inside this same transaction (same rationale as `enroll()`), and
     * the source row is always marked `transferred` BEFORE the target
     * `active` row is inserted, in the SAME transaction -- so a
     * same-group switch (source Group X -> target also Group X) never
     * spuriously trips the partial unique index: by the time the target
     * INSERT runs, the source's slot in that index is already released.
     * A genuinely occupied different-group target still correctly
     * rejects via that same index, rolling back the whole transaction
     * (the source row is never actually left `transferred` in that
     * case -- Postgres/Laravel roll back the entire transaction,
     * including the earlier UPDATE).
     */
    public function transfer(StudentSubjectEnrollment $sourceEnrollment, SubjectOffering $targetOffering, string $effectiveDate, ?User $actor = null): StudentSubjectEnrollment
    {
        if ($sourceEnrollment->school_id !== $targetOffering->school_id) {
            throw new CrossSchoolSubjectEnrollmentException;
        }

        return $this->context->withSchool($sourceEnrollment->school, function () use ($sourceEnrollment, $targetOffering, $effectiveDate, $actor) {
            try {
                return DB::transaction(function () use ($sourceEnrollment, $targetOffering, $effectiveDate, $actor) {
                    $lockedSource = StudentSubjectEnrollment::query()->whereKey($sourceEnrollment->id)->lockForUpdate()->firstOrFail();

                    if ($lockedSource->status !== 'active') {
                        throw new InvalidSubjectEnrollmentTransitionException($lockedSource->status, 'transferred');
                    }

                    $lockedTargetOffering = $this->lockOffering($targetOffering, $lockedSource->school_id);

                    $this->assertOfferingIsActive($lockedTargetOffering);

                    if ($lockedTargetOffering->is_required) {
                        throw new RequiredSubjectOfferingEnrollmentException;
                    }

                    $studentEnrollment = $this->resolveCompatibleEnrollment($lockedSource->student, $lockedTargetOffering);

                    $sourceEndsOn = Carbon::parse($effectiveDate)->subDay()->toDateString();
                    if ($sourceEndsOn < $lockedSource->starts_on->toDateString()) {
                        throw new InvalidEnrollmentDateRangeException(
                            "Switch effective date must be after the source subject enrollment's start date ({$lockedSource->starts_on->toDateString()})."
                        );
                    }

                    $affected = StudentSubjectEnrollment::query()
                        ->whereKey($lockedSource->id)
                        ->where('status', 'active')
                        ->update(['status' => 'transferred', 'ends_on' => $sourceEndsOn]);

                    if ($affected === 0) {
                        throw new InvalidSubjectEnrollmentTransitionException($lockedSource->fresh()->status ?? 'unknown', 'transferred');
                    }

                    $this->audit->school($lockedSource->school, 'student_subject_enrollment.transferred', actor: $actor, subject: $lockedSource, metadata: [
                        'studentId' => $lockedSource->student_id,
                        'fromSubjectOfferingId' => $lockedSource->subject_offering_id,
                        'toSubjectOfferingId' => $lockedTargetOffering->id,
                        'toStudentEnrollmentId' => $studentEnrollment->id,
                        'toElectiveGroupId' => $lockedTargetOffering->elective_group_id,
                        'endsOn' => $sourceEndsOn,
                    ]);

                    return $this->createRow($lockedSource->student, $lockedTargetOffering, $studentEnrollment, $effectiveDate, $actor);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * Phase 1C.1A: a SubjectOffering must be active to become the
     * TARGET of new participation -- mirrors the "deactivate, never
     * delete" reference-entity convention every other Academic
     * Structure entity already follows (docs/modules/ACADEMIC-STRUCTURE.md)
     * and the identical precedent
     * App\Domain\HR\Application\EmployeeAssignmentService already
     * enforces for Position/Department. Deliberately never called for
     * withdraw()/cancel()/transfer()'s SOURCE offering -- existing
     * participation against an offering that later becomes inactive
     * must remain fully manageable (history is preserved, only NEW
     * participation is gated).
     */
    private function assertOfferingIsActive(SubjectOffering $offering): void
    {
        if (! $offering->isActive()) {
            throw new InactiveSubjectOfferingException;
        }
    }

    /**
     * Phase 1F.1 section 16A: the target SubjectOffering must be locked
     * and reloaded from the database BEFORE this call site relies on its
     * `status`/`is_required`/`elective_group_id` -- a caller-held
     * `$offering` model may be stale (loaded before a concurrent
     * configuration change, or simply held across an earlier request).
     * Scoped by `school_id` in addition to the primary key, matching
     * every other tenant-scoped lookup in this codebase (never an
     * unqualified `whereKey()` for a row whose identity matters for
     * authorization/invariant purposes).
     */
    private function lockOffering(SubjectOffering $offering, string $schoolId): SubjectOffering
    {
        return SubjectOffering::query()
            ->where('school_id', $schoolId)
            ->whereKey($offering->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Phase 1C.1 section 11: the Student must have a CURRENT active
     * StudentEnrollment whose AcademicYear/GradeLevel/Campus matches the
     * chosen SubjectOffering. This is checked at write time only --
     * roster READS re-derive this same check fresh every time (see
     * App\Domain\Students\Application\SubjectOfferingRosterReadService)
     * so a later incompatible StudentEnrollment change is caught without
     * this row needing to be touched.
     *
     * Phase 1F.2: returns the resolved StudentEnrollment itself (not
     * merely a boolean), because this exact row -- the one just proven
     * compatible -- becomes the new StudentSubjectEnrollment's
     * `student_enrollment_id` placement anchor (architecture doc §11B).
     * At most one row can ever match: `student_enrollments_one_active_per_student_year`
     * guarantees at most one `active` StudentEnrollment per Student per
     * AcademicYear, and `$offering->academic_year_id` is fixed.
     */
    private function resolveCompatibleEnrollment(Student $student, SubjectOffering $offering): StudentEnrollment
    {
        $enrollment = StudentEnrollment::query()
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('grade_level_id', $offering->grade_level_id)
            ->where('campus_id', $offering->campus_id)
            ->first();

        if ($enrollment === null) {
            throw new IncompatibleSubjectOfferingException;
        }

        return $enrollment;
    }

    private function transitionToTerminalStatus(StudentSubjectEnrollment $enrollment, string $newStatus, string $endedOn, string $eventType, ?User $actor): StudentSubjectEnrollment
    {
        return $this->context->withSchool($enrollment->school, fn () => DB::transaction(function () use ($enrollment, $newStatus, $endedOn, $eventType, $actor) {
            $locked = StudentSubjectEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw new InvalidSubjectEnrollmentTransitionException($locked->status, $newStatus);
            }

            if ($endedOn < $locked->starts_on->toDateString()) {
                throw new InvalidEnrollmentDateRangeException(
                    "End date must not be before the subject enrollment's start date ({$locked->starts_on->toDateString()})."
                );
            }

            $affected = StudentSubjectEnrollment::query()
                ->whereKey($locked->id)
                ->where('status', 'active')
                ->update(['status' => $newStatus, 'ends_on' => $endedOn]);

            if ($affected === 0) {
                throw new InvalidSubjectEnrollmentTransitionException($locked->fresh()->status ?? 'unknown', $newStatus);
            }

            $this->audit->school($locked->school, $eventType, actor: $actor, subject: $locked, metadata: [
                'studentId' => $locked->student_id,
                'subjectOfferingId' => $locked->subject_offering_id,
                'endsOn' => $endedOn,
            ]);

            return $locked->refresh();
        }));
    }

    /**
     * Phase 1F.2: every canonical row this service creates now persists
     * its authoritative `student_enrollment_id` (the exact compatible
     * StudentEnrollment `resolveCompatibleEnrollment()` resolved --
     * never re-derived independently here) and `elective_group_id`
     * (snapshotted from the already-locked `$offering`, whether that
     * value is a real ElectiveGroup id or NULL for an ungrouped
     * offering). Both are populated for EVERY new row, grouped or not
     * -- architecture doc §18B's accepted "new-row policy" -- never left
     * NULL merely because the column permits it for legacy rows.
     */
    private function createRow(Student $student, SubjectOffering $offering, StudentEnrollment $studentEnrollment, string $startsOn, ?User $actor): StudentSubjectEnrollment
    {
        $enrollment = StudentSubjectEnrollment::query()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'student_enrollment_id' => $studentEnrollment->id,
            'subject_offering_id' => $offering->id,
            'elective_group_id' => $offering->elective_group_id,
            'academic_year_id' => $offering->academic_year_id,
            'status' => 'active',
            'starts_on' => $startsOn,
        ]);

        $this->audit->school($student->school, 'student_subject_enrollment.created', actor: $actor, subject: $enrollment, metadata: [
            'studentId' => $student->id,
            'studentEnrollmentId' => $studentEnrollment->id,
            'subjectOfferingId' => $offering->id,
            'electiveGroupId' => $offering->elective_group_id,
            'academicYearId' => $offering->academic_year_id,
        ]);

        return $enrollment;
    }

    /**
     * Phase 1F.2: distinguishes the pre-existing same-offering duplicate
     * invariant from the new group-exclusivity invariant by the exact
     * violated index/constraint name -- never a blanket "any 23505 means
     * X" translation (which would also swallow the placement-anchor FK
     * or the group-context FK under an unrelated failure mode). Neither
     * the SQLSTATE nor the constraint/index name themselves are ever
     * exposed on the resulting domain exception.
     */
    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'student_subject_enrollments_one_active_per_offering' => new ActiveSubjectEnrollmentConflictException,
            'student_subject_enrollments_one_active_per_elective_group' => new ElectiveGroupConflictException,
            default => $e,
        };
    }
}
