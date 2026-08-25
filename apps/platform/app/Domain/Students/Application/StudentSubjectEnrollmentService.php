<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\ActiveSubjectEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectEnrollmentException;
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
     */
    public function enroll(Student $student, SubjectOffering $offering, string $startsOn, ?User $actor = null): StudentSubjectEnrollment
    {
        if ($student->school_id !== $offering->school_id) {
            throw new CrossSchoolSubjectEnrollmentException;
        }

        if ($offering->is_required) {
            throw new RequiredSubjectOfferingEnrollmentException;
        }

        return $this->context->withSchool($student->school, function () use ($student, $offering, $startsOn, $actor) {
            $this->assertCompatible($student, $offering);

            try {
                return DB::transaction(fn () => $this->createRow($student, $offering, $startsOn, $actor));
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
     * exact shape. Does NOT enforce elective-group mutual exclusivity
     * (no such grouping concept exists yet -- see the migration's
     * docblock); it only guarantees the two rows involved in THIS
     * switch are handled atomically and historically.
     */
    public function transfer(StudentSubjectEnrollment $sourceEnrollment, SubjectOffering $targetOffering, string $effectiveDate, ?User $actor = null): StudentSubjectEnrollment
    {
        if ($sourceEnrollment->school_id !== $targetOffering->school_id) {
            throw new CrossSchoolSubjectEnrollmentException;
        }

        if ($targetOffering->is_required) {
            throw new RequiredSubjectOfferingEnrollmentException;
        }

        return $this->context->withSchool($sourceEnrollment->school, function () use ($sourceEnrollment, $targetOffering, $effectiveDate, $actor) {
            $this->assertCompatible($sourceEnrollment->student, $targetOffering);

            try {
                return DB::transaction(function () use ($sourceEnrollment, $targetOffering, $effectiveDate, $actor) {
                    $lockedSource = StudentSubjectEnrollment::query()->whereKey($sourceEnrollment->id)->lockForUpdate()->firstOrFail();

                    if ($lockedSource->status !== 'active') {
                        throw new InvalidSubjectEnrollmentTransitionException($lockedSource->status, 'transferred');
                    }

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
                        'toSubjectOfferingId' => $targetOffering->id,
                        'endsOn' => $sourceEndsOn,
                    ]);

                    return $this->createRow($lockedSource->student, $targetOffering, $effectiveDate, $actor);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * Phase 1C.1 section 11: the Student must have a CURRENT active
     * StudentEnrollment whose AcademicYear/GradeLevel/Campus matches the
     * chosen SubjectOffering. This is checked at write time only --
     * roster READS re-derive this same check fresh every time (see
     * App\Domain\Students\Application\SubjectOfferingRosterReadService)
     * so a later incompatible StudentEnrollment change is caught without
     * this row needing to be touched.
     */
    private function assertCompatible(Student $student, SubjectOffering $offering): void
    {
        $compatible = StudentEnrollment::query()
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('grade_level_id', $offering->grade_level_id)
            ->where('campus_id', $offering->campus_id)
            ->exists();

        if (! $compatible) {
            throw new IncompatibleSubjectOfferingException;
        }
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

    private function createRow(Student $student, SubjectOffering $offering, string $startsOn, ?User $actor): StudentSubjectEnrollment
    {
        $enrollment = StudentSubjectEnrollment::query()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'academic_year_id' => $offering->academic_year_id,
            'status' => 'active',
            'starts_on' => $startsOn,
        ]);

        $this->audit->school($student->school, 'student_subject_enrollment.created', actor: $actor, subject: $enrollment, metadata: [
            'studentId' => $student->id,
            'subjectOfferingId' => $offering->id,
            'academicYearId' => $offering->academic_year_id,
        ]);

        return $enrollment;
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'student_subject_enrollments_one_active_per_offering' => new ActiveSubjectEnrollmentConflictException,
            default => $e,
        };
    }
}
