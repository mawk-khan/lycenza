<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossAcademicYearTransferException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\IntraYearGradeChangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1B.2/1B.3: the ONLY sanctioned write path for a
 * StudentEnrollment -- creation (`enroll()`), and the four terminal
 * lifecycle transitions (`complete()`/`withdraw()`/`cancel()`) plus the
 * atomic intra-AcademicYear placement move (`transferPlacement()`).
 * Never create/update a StudentEnrollment row directly from a future
 * controller/import, and never accept `academic_year_id`/`campus_id`/
 * `grade_level_id`/`section_id` as independent caller input anywhere in
 * this service (rule 19's "school_id never comes from request payload"
 * principle, applied to every academic-placement reference this
 * service writes) -- they are always DERIVED from a caller-supplied
 * Section server-side. See docs/modules/STUDENT-ENROLLMENT.md
 * ("Sanctioned Enrollment creation path", "Lifecycle transitions",
 * "Same-Academic-Year transfer") for the full rationale behind every
 * rule enforced here.
 *
 * There is NO generic status setter (e.g. `changeStatus($enrollment,
 * $status)`) -- every transition is its own method with its own
 * eligibility/date rules, matching this checkpoint's explicit brief.
 * Terminal statuses (`completed`/`withdrawn`/`cancelled`/`transferred`)
 * never transition again through this service, including back to
 * `active` -- re-enrollment is always a brand-new StudentEnrollment
 * row (the historical-record principle, Phase 1B.1).
 *
 * Every lifecycle method reloads and `lockForUpdate()`s the
 * authoritative row inside its transaction before evaluating the
 * transition (never trusts a possibly-stale `$enrollment->status` the
 * caller already holds), then performs a conditional
 * `WHERE status = 'active'` UPDATE and checks the affected-row count --
 * the exact double-guard pattern
 * App\Domain\AcademicStructure\Application\AcademicYearService::activate()
 * already established for the identical "reload, lock, conditionally
 * transition, detect a lost race" shape.
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase -- a future controller must call
 * `Gate::authorize`/`authorizeCapability` before ever reaching this
 * service; no capability check lives here.
 */
class StudentEnrollmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Enrolls a Student into a Section for the AcademicYear/Campus/
     * GradeLevel that Section already belongs to -- the Section is the
     * single, authoritative placement input; a caller has no way to
     * independently express a different AcademicYear/Campus/GradeLevel
     * than the one the chosen Section actually has.
     *
     * Always creates the Enrollment with status `active` and no
     * `ends_on`.
     */
    public function enroll(Student $student, Section $section, string $rollNumber, string $startsOn, ?User $actor = null): StudentEnrollment
    {
        if ($student->school_id !== $section->school_id) {
            throw new CrossSchoolEnrollmentException;
        }

        $rollNumber = $this->normalizeRollNumber($rollNumber);

        return $this->context->withSchool($student->school, function () use ($student, $section, $rollNumber, $startsOn, $actor) {
            try {
                return DB::transaction(fn () => $this->createEnrollmentRow($student, $section, $rollNumber, $startsOn, $actor));
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e, $rollNumber);
            }
        });
    }

    /**
     * Normal completion of an Enrollment -- e.g. the academic year's
     * teaching period genuinely ended for this Student in this
     * placement. Deliberately does NOT create the next AcademicYear's
     * Enrollment, change GradeLevel, allocate a Section, allocate a
     * roll number, or touch Student.status -- that orchestration is a
     * future dedicated rollover checkpoint's job, never this method's.
     */
    public function complete(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'completed', $endedOn, 'student_enrollment.completed', $actor);
    }

    /**
     * The Student left this placement before it ran its normal course
     * (e.g. a mid-year withdrawal from the School). Never touches
     * Student.status or any Guardian relationship -- Enrollment
     * lifecycle is not Student identity lifecycle; the current
     * architectural default is that these two remain fully independent
     * unless an already-accepted Student-domain rule says otherwise
     * (none does today).
     */
    public function withdraw(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'withdrawn', $endedOn, 'student_enrollment.withdrawn', $actor);
    }

    /**
     * The Enrollment should not continue as an operational placement --
     * distinct from `withdraw()` (the placement WAS operational and the
     * Student subsequently left it): a cancelled Enrollment is retained
     * purely as an administrative historical record, never deleted. See
     * docs/modules/STUDENT-ENROLLMENT.md ("Cancellation semantics") for
     * the narrow interpretation this checkpoint adopts in the absence
     * of any existing product rule distinguishing the two.
     */
    public function cancel(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'cancelled', $endedOn, 'student_enrollment.cancelled', $actor);
    }

    /**
     * Moves a Student from their current active Enrollment into a
     * different Section within the SAME AcademicYear -- an atomic,
     * two-row historical operation (mark the old row `transferred`,
     * create a brand-new `active` row for the target Section), never a
     * placement-column UPDATE on the existing row. If creating the new
     * Enrollment fails for any reason (duplicate roll number, an
     * unexpected active-enrollment conflict, an FK/integrity failure),
     * the ENTIRE operation rolls back -- the source Enrollment is left
     * exactly as it was (still `active`, original `ends_on`), never
     * half-transferred.
     *
     * `$effectiveDate` is the first date the NEW placement is
     * effective; the source Enrollment's `ends_on` is derived as the
     * calendar day immediately before it (docs/modules/STUDENT-ENROLLMENT.md
     * "Transfer date semantics") -- `ends_on` is always inclusive: the
     * LAST date a placement is effective, never the day it stops.
     *
     * The target Section must belong to the same School (composite-FK/
     * RLS-protected as the authoritative backstop; checked first here
     * for a clean error), the same AcademicYear as the source
     * Enrollment (this is an intra-year transfer only -- a different
     * AcademicYear is promotion/rollover, deliberately deferred), and
     * the same GradeLevel as the source Enrollment (this checkpoint's
     * safer default -- an intra-year GradeLevel change is treated as
     * promotion/reclassification, not a transfer, in the absence of any
     * existing Academic Structure rule that says otherwise). A
     * same-School Campus change IS allowed -- the target Campus is
     * simply whatever Campus the target Section belongs to; no separate
     * campus-transfer path exists because Section already authoritatively
     * owns its Campus.
     */
    public function transferPlacement(StudentEnrollment $sourceEnrollment, Section $targetSection, string $rollNumber, string $effectiveDate, ?User $actor = null): StudentEnrollment
    {
        if ($sourceEnrollment->school_id !== $targetSection->school_id) {
            throw new CrossSchoolEnrollmentException;
        }
        if ($sourceEnrollment->academic_year_id !== $targetSection->academic_year_id) {
            throw new CrossAcademicYearTransferException;
        }
        if ($sourceEnrollment->grade_level_id !== $targetSection->grade_level_id) {
            throw new IntraYearGradeChangeException;
        }

        $rollNumber = $this->normalizeRollNumber($rollNumber);

        return $this->context->withSchool($sourceEnrollment->school, function () use ($sourceEnrollment, $targetSection, $rollNumber, $effectiveDate, $actor) {
            try {
                return DB::transaction(function () use ($sourceEnrollment, $targetSection, $rollNumber, $effectiveDate, $actor) {
                    $lockedSource = StudentEnrollment::query()->whereKey($sourceEnrollment->id)->lockForUpdate()->firstOrFail();

                    if ($lockedSource->status !== 'active') {
                        throw new InvalidEnrollmentTransitionException($lockedSource->status, 'transferred');
                    }

                    $sourceEndsOn = Carbon::parse($effectiveDate)->subDay()->toDateString();
                    if ($sourceEndsOn < $lockedSource->starts_on->toDateString()) {
                        throw new InvalidEnrollmentDateRangeException(
                            "Transfer effective date must be after the source Enrollment's start date ({$lockedSource->starts_on->toDateString()})."
                        );
                    }

                    $affected = StudentEnrollment::query()
                        ->whereKey($lockedSource->id)
                        ->where('status', 'active')
                        ->update(['status' => 'transferred', 'ends_on' => $sourceEndsOn]);

                    if ($affected === 0) {
                        throw new InvalidEnrollmentTransitionException($lockedSource->fresh()->status ?? 'unknown', 'transferred');
                    }

                    $this->audit->school($lockedSource->school, 'student_enrollment.transferred', actor: $actor, subject: $lockedSource, metadata: [
                        'studentId' => $lockedSource->student_id,
                        'fromSectionId' => $lockedSource->section_id,
                        'toSectionId' => $targetSection->id,
                        'endsOn' => $sourceEndsOn,
                    ]);

                    // Same transaction as the source transition above --
                    // if this fails (roll-number conflict, unexpected
                    // active-conflict, integrity failure), the whole
                    // transaction (including the source UPDATE and its
                    // audit event just above) rolls back together, so
                    // the source Enrollment is never left half-transferred
                    // and no false "transferred" audit record survives.
                    return $this->createEnrollmentRow($lockedSource->student, $targetSection, $rollNumber, $effectiveDate, $actor);
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e, $rollNumber);
            }
        });
    }

    private function transitionToTerminalStatus(StudentEnrollment $enrollment, string $newStatus, string $endedOn, string $eventType, ?User $actor): StudentEnrollment
    {
        return $this->context->withSchool($enrollment->school, fn () => DB::transaction(function () use ($enrollment, $newStatus, $endedOn, $eventType, $actor) {
            $locked = StudentEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw new InvalidEnrollmentTransitionException($locked->status, $newStatus);
            }

            if ($endedOn < $locked->starts_on->toDateString()) {
                throw new InvalidEnrollmentDateRangeException(
                    "End date must not be before the Enrollment's start date ({$locked->starts_on->toDateString()})."
                );
            }

            $affected = StudentEnrollment::query()
                ->whereKey($locked->id)
                ->where('status', 'active')
                ->update(['status' => $newStatus, 'ends_on' => $endedOn]);

            if ($affected === 0) {
                throw new InvalidEnrollmentTransitionException($locked->fresh()->status ?? 'unknown', $newStatus);
            }

            $this->audit->school($locked->school, $eventType, actor: $actor, subject: $locked, metadata: [
                'studentId' => $locked->student_id,
                'academicYearId' => $locked->academic_year_id,
                'sectionId' => $locked->section_id,
                'endsOn' => $endedOn,
            ]);

            return $locked->refresh();
        }));
    }

    /**
     * Shared, transaction-boundary-agnostic raw write -- assumes the
     * caller has already established TenantContext and a surrounding
     * DB::transaction(). Never called directly from outside this
     * class; `enroll()` and `transferPlacement()` are the only two
     * sanctioned entry points, each owning its own transaction and its
     * own UniqueConstraintViolationException translation (the two
     * callers need different context for the roll-number-conflict
     * message, and transferPlacement() additionally needs its source-row
     * transition inside the SAME transaction as this write).
     */
    private function createEnrollmentRow(Student $student, Section $section, string $rollNumber, string $startsOn, ?User $actor): StudentEnrollment
    {
        $enrollment = StudentEnrollment::query()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'academic_year_id' => $section->academic_year_id,
            'campus_id' => $section->campus_id,
            'grade_level_id' => $section->grade_level_id,
            'section_id' => $section->id,
            'roll_number' => $rollNumber,
            'status' => 'active',
            'starts_on' => $startsOn,
        ]);

        $this->audit->school($student->school, 'student_enrollment.created', actor: $actor, subject: $enrollment, metadata: [
            'studentId' => $student->id,
            'academicYearId' => $section->academic_year_id,
            'campusId' => $section->campus_id,
            'gradeLevelId' => $section->grade_level_id,
            'sectionId' => $section->id,
        ]);

        return $enrollment;
    }

    private function normalizeRollNumber(string $rollNumber): string
    {
        $rollNumber = trim($rollNumber);
        if ($rollNumber === '') {
            throw new InvalidEnrollmentRollNumberException;
        }

        return $rollNumber;
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e, string $rollNumber): Throwable
    {
        return match ($e->index) {
            'student_enrollments_one_active_per_student_year' => new ActiveEnrollmentConflictException,
            'student_enrollments_school_id_academic_year_id_section_id_roll_' => new DuplicateEnrollmentRollNumberException($rollNumber),
            default => $e,
        };
    }
}
