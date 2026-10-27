<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossAcademicYearTransferException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\IntraYearGradeChangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\PlacementDependentsBlockException;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Dates\CalendarDate;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
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
 *
 * SECTION-BEFORE-ENROLLMENT LOCK ORDER (Phase 0H.2). Every method here
 * that creates or transitions a placement first takes a
 * `SELECT ... FOR UPDATE` on the Section row(s) the operation touches,
 * and only THEN locks/re-reads the Enrollment row(s). This is a
 * synchronization discipline, not a new business rule -- no domain
 * outcome changes -- and it exists because
 * App\Domain\Attendance\Application\AttendanceSubmissionService must be
 * able to derive a complete as-of-date Section roster, validate a
 * submitted register against it as an EXACT SET, and write that
 * register, without a concurrent membership change landing in between.
 * Attendance holds the same Section row lock across exactly that
 * window, so the two modules serialize on one shared row.
 *
 * The previous "lock the Enrollment, then read its Section" order is
 * deliberately gone everywhere: keeping it would give Attendance
 * (Section -> Enrollment) and SIS (Enrollment -> Section) opposite
 * acquisition orders and therefore a genuine deadlock. The preliminary
 * unlocked read each method now performs is DISCOVERY ONLY -- it
 * exists solely to learn which Section row to lock. Every mutation
 * still relies on the locked re-read, which re-verifies both the
 * expected status AND that the Enrollment still belongs to the Section
 * that was actually locked (a concurrent transfer could have moved it
 * between the discovery read and the lock).
 *
 * `transferPlacement()` touches TWO Sections, so it locks both in
 * ascending id order -- a stable global ordering that makes an
 * A->B / B->A pair of concurrent transfers serialize instead of
 * deadlocking (proven with two real OS processes in
 * `Tests\Feature\Students\OppositeDirectionTransferConcurrencyTest`).
 */
class StudentEnrollmentService
{
    /**
     * @param  iterable<StudentTransferBlocker>  $transferBlockers
     * @param  iterable<PlacementCancellationBlocker>  $cancellationBlockers
     */
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ActiveCampus $campuses,
        private readonly ApprovalDecisionAuthority $authority,
        #[Tag(StudentTransferBlocker::TAG)] private readonly iterable $transferBlockers = [],
        #[Tag(PlacementCancellationBlocker::TAG)] private readonly iterable $cancellationBlockers = [],
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

        $rollNumber = RollNumberNormalizer::normalize($rollNumber);

        return $this->context->withSchool($student->school, function () use ($student, $section, $rollNumber, $startsOn, $actor) {
            try {
                return DB::transaction(function () use ($student, $section, $rollNumber, $startsOn, $actor) {
                    // ORG.7F-2: the Student FIRST (FOR SHARE), then the
                    // Section lock before any Enrollment mutation (this
                    // class's own docblock), then the placement-validity
                    // check under those locks. SIS 4.1: the Section's
                    // Academic Year sits between them (FOR SHARE).
                    $lockedStudent = $this->lockStudentForPlacement($student->id);
                    $year = $this->lockAcademicYearForPlacement($section->academic_year_id);
                    $sections = $this->lockSections([$section->id]);
                    $startsOn = $this->assertPlaceable($lockedStudent, $sections[$section->id], $year, $startsOn);

                    return $this->createEnrollmentRow($lockedStudent, $sections[$section->id], $rollNumber, $startsOn, $actor);
                });
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
    public function withdraw(StudentEnrollment $enrollment, string $endedOn, string $reasonCode, User $actor, FreshMfaProof $mfa): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'withdrawn', $endedOn, 'student_enrollment.withdrawn', $actor, [StudentLifecycleReason::WITHDRAW_ENROLLMENT, $reasonCode, $mfa]);
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
    public function cancel(StudentEnrollment $enrollment, string $endedOn, string $reasonCode, User $actor, FreshMfaProof $mfa): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'cancelled', $endedOn, 'student_enrollment.cancelled', $actor, [StudentLifecycleReason::CANCEL_ENROLLMENT, $reasonCode, $mfa]);
    }

    /**
     * SIS 4.2 (owner decision): cancel a placement that has NOT STARTED yet
     * (its start date is after today, School-local) -- typically a next-year
     * placement entered by mistake, or a Student who will not join. The same
     * closed cancel reasons, fresh MFA, current authority and lifecycle event
     * as cancel(). It was never effective, so it records the empty interval
     * `ends_on = starts_on - 1` (never on a roster, never current); the real
     * time is the database's `ended_recorded_at`, the event and the audit.
     * Refused while a PlacementCancellationBlocker reports a record that
     * already depends on it. Never deletes the row.
     */
    public function cancelBeforeStart(StudentEnrollment $enrollment, string $reasonCode, User $actor, FreshMfaProof $mfa): StudentEnrollment
    {
        return $this->transitionToTerminalStatus($enrollment, 'cancelled', '', 'student_enrollment.cancelled', $actor, [StudentLifecycleReason::CANCEL_ENROLLMENT, $reasonCode, $mfa], beforeStart: true);
    }

    /**
     * SIS 4.2: correct the roll number of an ACTIVE placement (current or
     * not yet started) -- the one column of a placement that is not part of
     * its identity (ADR 0076 §47.1). An ended placement's roll number is
     * history and stays. Section-before-Enrollment locks; the number stays
     * unique in the Section for the year (any status, the existing key), so a
     * number in use -- even on an ended placement -- is the same clean refusal
     * as at enrollment. Audited with the old and new number.
     */
    public function correctRollNumber(StudentEnrollment $enrollment, string $rollNumber, User $actor): StudentEnrollment
    {
        $rollNumber = RollNumberNormalizer::normalize($rollNumber);

        return $this->context->withSchool($enrollment->school, function () use ($enrollment, $rollNumber, $actor): StudentEnrollment {
            try {
                return DB::transaction(function () use ($enrollment, $rollNumber, $actor): StudentEnrollment {
                    $discovered = StudentEnrollment::query()->whereKey($enrollment->id)->firstOrFail();
                    $this->lockSections([$discovered->section_id]);
                    $locked = StudentEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

                    if ($locked->section_id !== $discovered->section_id || $locked->status !== 'active') {
                        throw new InvalidEnrollmentTransitionException($locked->status, 'roll number corrected');
                    }
                    if ($locked->roll_number === $rollNumber) {
                        return $locked;
                    }
                    $taken = StudentEnrollment::query()
                        ->where('academic_year_id', $locked->academic_year_id)
                        ->where('section_id', $locked->section_id)
                        ->where('roll_number', $rollNumber)
                        ->whereKeyNot($locked->id)
                        ->exists();
                    if ($taken) {
                        throw new DuplicateEnrollmentRollNumberException($rollNumber);
                    }

                    $before = $locked->roll_number;
                    $locked->update(['roll_number' => $rollNumber]);

                    $this->audit->school($locked->school, 'student_enrollment.roll_number_corrected', actor: $actor, subject: $locked, metadata: [
                        'studentId' => $locked->student_id,
                        'sectionId' => $locked->section_id,
                        'before' => $before,
                        'after' => $rollNumber,
                    ]);

                    return $locked->refresh();
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e, $rollNumber);
            }
        });
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
    public function transferPlacement(StudentEnrollment $sourceEnrollment, Section $targetSection, string $rollNumber, string $effectiveDate, ?User $actor = null, ?StudentTransferCampusGuard $campusGuard = null): StudentEnrollment
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

        $rollNumber = RollNumberNormalizer::normalize($rollNumber);

        return $this->context->withSchool($sourceEnrollment->school, function () use ($sourceEnrollment, $targetSection, $rollNumber, $effectiveDate, $actor, $campusGuard) {
            try {
                return DB::transaction(function () use ($sourceEnrollment, $targetSection, $rollNumber, $effectiveDate, $actor, $campusGuard) {
                    // Section-before-Enrollment, TWO Sections (this
                    // class's docblock): discover the source Section
                    // from an unlocked read, then lock BOTH Sections in
                    // ascending id order -- the stable global ordering
                    // that stops an A->B / B->A pair of concurrent
                    // transfers from deadlocking -- and only then lock
                    // the source Enrollment.
                    // ORG.7F-2: the Student is locked FIRST (FOR SHARE) --
                    // before the Sections and the source Enrollment -- so a
                    // Campus write guard holding the Student (FOR NO KEY
                    // UPDATE) and then waiting for this Enrollment can never
                    // form a cycle with this transfer (ADR 0076 §44.11).
                    $lockedStudent = $this->lockStudentForPlacement($sourceEnrollment->student_id);
                    // SIS 4.1: the one Academic Year both placements share
                    // (checked above), FOR SHARE before the Sections.
                    $year = $this->lockAcademicYearForPlacement($targetSection->academic_year_id);
                    $discovered = StudentEnrollment::query()->whereKey($sourceEnrollment->id)->firstOrFail();
                    $sections = $this->lockSections([$discovered->section_id, $targetSection->id]);

                    $lockedSource = StudentEnrollment::query()->whereKey($sourceEnrollment->id)->lockForUpdate()->firstOrFail();

                    // The source Enrollment must still belong to the
                    // Section we actually locked; a concurrent transfer
                    // between the discovery read and this lock would
                    // otherwise leave this operation unserialized
                    // against Attendance for the real Section.
                    if ($lockedSource->section_id !== $discovered->section_id) {
                        throw new InvalidEnrollmentTransitionException($lockedSource->status, 'transferred');
                    }

                    if ($lockedSource->status !== 'active') {
                        throw new InvalidEnrollmentTransitionException($lockedSource->status, 'transferred');
                    }

                    // ORG.7F-3 (ADR 0076 §47): a Campus-scoped transfer's
                    // dual-scope, currentness and blocker checks -- under
                    // every lock above (none for the whole School).
                    $campusGuard?->beforeTransfer($lockedStudent, $lockedSource, $sections[$targetSection->id]);

                    // SIS 4.2: the whole-School transfer asks the same blockers
                    // whenever the Campus changes (a same-Campus Section change
                    // leaves Hostel, Transport and elective records valid), and
                    // names what must be resolved first. Same locks, same answer.
                    if ($campusGuard === null && $lockedSource->campus_id !== $sections[$targetSection->id]->campus_id) {
                        $kinds = [];
                        foreach ($this->transferBlockers as $blocker) {
                            if ($blocker->blocksCampusTransfer($lockedSource->school, $lockedStudent->id, $lockedSource->id, $lockedSource->campus_id)) {
                                $kinds[] = $blocker->kind();
                            }
                        }
                        if ($kinds !== []) {
                            throw PlacementDependentsBlockException::transfer($kinds);
                        }
                    }

                    $sourceEndsOn = Carbon::parse($effectiveDate)->subDay()->toDateString();
                    if ($sourceEndsOn < $lockedSource->starts_on->toDateString()) {
                        throw new InvalidEnrollmentDateRangeException(
                            'Transfer effective date must be after the source Enrollment\'s start date ('.CalendarDate::human($lockedSource->starts_on).').'
                        );
                    }
                    $this->assertEndsWithinYear($lockedSource, $sourceEndsOn);

                    $affected = StudentEnrollment::query()
                        ->whereKey($lockedSource->id)
                        ->where('status', 'active')
                        ->update(['status' => 'transferred', 'ends_on' => $sourceEndsOn]);

                    if ($affected === 0) {
                        throw new InvalidEnrollmentTransitionException($lockedSource->fresh()->status ?? 'unknown', 'transferred');
                    }

                    // Same transaction as the source transition above --
                    // if this fails (roll-number conflict, unexpected
                    // active-conflict, integrity failure), the whole
                    // transaction (including the source UPDATE just above)
                    // rolls back together, so
                    // the source Enrollment is never left half-transferred
                    // and no false "transferred" audit record survives.
                    $effectiveDate = $this->assertPlaceable($lockedStudent, $sections[$targetSection->id], $year, $effectiveDate);

                    $target = $this->createEnrollmentRow($lockedStudent, $sections[$targetSection->id], $rollNumber, $effectiveDate, $actor);

                    // ORG.7F-3: explicit source and destination Campus, Section
                    // and Enrollment ids, so the transfer's provenance never
                    // depends on the Student's current Campus.
                    $this->audit->school($lockedSource->school, 'student_enrollment.transferred', actor: $actor, subject: $lockedSource, metadata: [
                        'studentId' => $lockedSource->student_id,
                        'fromEnrollmentId' => $lockedSource->id,
                        'toEnrollmentId' => $target->id,
                        'fromCampusId' => $lockedSource->campus_id,
                        'toCampusId' => $target->campus_id,
                        'fromSectionId' => $lockedSource->section_id,
                        'toSectionId' => $target->section_id,
                        'endsOn' => $sourceEndsOn,
                        'scope' => $campusGuard === null ? 'school' : 'campus',
                    ]);

                    return $target;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e, $rollNumber);
            }
        });
    }

    /**
     * Section-before-Enrollment order (this class's docblock):
     *
     *   1. preliminary UNLOCKED read -- discovery only, purely to learn
     *      which Section row to lock (never trusted for the decision);
     *   2. lock that Section;
     *   3. lock/re-read the Enrollment;
     *   4. verify it is still `active` AND still belongs to the Section
     *      that was actually locked;
     *   5. transition.
     *
     * Step 4's Section re-verification is what makes step 1's unlocked
     * read safe: if a concurrent transfer moved the Enrollment to a
     * different Section between the discovery read and the lock, we
     * hold the WRONG Section's lock, so the transition is refused
     * rather than proceeding unserialized against Attendance.
     */
    /**
     * ORG.8E (ADR 0076 §63, 8E-D1): `$assurance` = [action, reason code, fresh
     * MFA proof] for the high-impact withdraw and cancel. They also re-read the
     * actor's CURRENT whole-School `enrollments.manage` under the Enrollment
     * lock and write the lifecycle event the database requires
     * (`student_enrollments_lifecycle_evidence`). Completion stays ordinary
     * but, since ORG.8G (ADR 0076 §65), only on the year's last day once it
     * has come; no end is ever dated in the future.
     *
     * @param  array{0: string, 1: string, 2: FreshMfaProof}|null  $assurance
     */
    private function transitionToTerminalStatus(StudentEnrollment $enrollment, string $newStatus, string $endedOn, string $eventType, ?User $actor, ?array $assurance = null, bool $beforeStart = false): StudentEnrollment
    {
        // ORG.8G: compare calendar dates, never the caller's string format.
        $endedOn = $beforeStart ? '' : Carbon::parse($endedOn)->toDateString();
        if ($assurance !== null) {
            [$action, $reasonCode, $mfa] = $assurance;
            if ($actor === null || ! $mfa->isFor($actor->id)) {
                throw new LogicException('A high-impact Enrollment action needs its actor\'s fresh MFA proof.');
            }
            StudentLifecycleReason::require($action, $reasonCode);
        }

        return $this->context->withSchool($enrollment->school, fn () => DB::transaction(function () use ($enrollment, $newStatus, $endedOn, $eventType, $actor, $assurance, $beforeStart) {
            $discovered = StudentEnrollment::query()->whereKey($enrollment->id)->firstOrFail();
            $this->lockSections([$discovered->section_id]);

            $locked = StudentEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->section_id !== $discovered->section_id) {
                throw new InvalidEnrollmentTransitionException($locked->status, $newStatus);
            }

            if ($locked->status !== 'active') {
                throw new InvalidEnrollmentTransitionException($locked->status, $newStatus);
            }

            $startsOn = $locked->starts_on->toDateString();
            $notStarted = $startsOn > SchoolTimezone::today($locked->school);
            if ($beforeStart) {
                // SIS 4.2: only a placement that has not started; the empty
                // interval ends the day before it would have begun.
                if (! $notStarted) {
                    throw new InvalidEnrollmentDateRangeException('This placement has already started. Cancel it with an end date instead.');
                }
                $endedOn = $locked->starts_on->copy()->subDay()->toDateString();
                $kinds = [];
                foreach ($this->cancellationBlockers as $blocker) {
                    if ($blocker->blocksCancellation($locked->school, $locked->id)) {
                        $kinds[] = $blocker->kind();
                    }
                }
                if ($kinds !== []) {
                    throw PlacementDependentsBlockException::cancellation($kinds);
                }
            } else {
                if ($notStarted) {
                    throw new InvalidEnrollmentDateRangeException('This placement starts on '.CalendarDate::human($startsOn).' and has not started yet. Use "Cancel before it starts" instead.');
                }
                if ($endedOn < $startsOn) {
                    throw new InvalidEnrollmentDateRangeException(
                        'End date must not be before the Enrollment\'s start date ('.CalendarDate::human($startsOn).').'
                    );
                }

                // ORG.8G (ADR 0076 §65): a placement never outlives its year
                // (the database refuses it too), and a withdrawal or
                // cancellation is never dated in the future -- there is no
                // scheduled termination.
                $this->assertEndsWithinYear($locked, $endedOn);
                if ($endedOn > SchoolTimezone::today($locked->school)) {
                    throw new InvalidEnrollmentDateRangeException('End date must not be in the future.');
                }
            }
            // Completion is the placement running its normal course: it ends on
            // its academic year's last day, once that day has come. Leaving
            // earlier is a withdrawal or cancellation (reason, fresh code).
            if ($newStatus === 'completed' && $endedOn !== $locked->academicYear()->firstOrFail()->ends_on->toDateString()) {
                throw new InvalidEnrollmentDateRangeException('A placement completes on its academic year\'s last day ('.CalendarDate::human($locked->academicYear()->firstOrFail()->ends_on).'); to end it earlier, withdraw or cancel it.');
            }

            $event = null;
            if ($assurance !== null && $actor !== null) {
                $this->authority->requireCurrent($actor, 'enrollments.manage', $locked->school);
                $event = StudentLifecycleEvent::query()->create([
                    'school_id' => $locked->school_id, 'student_id' => $locked->student_id, 'student_enrollment_id' => $locked->id,
                    'action' => $assurance[0], 'reason_code' => $assurance[1], 'acted_by_user_id' => $actor->id,
                ]);
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
            ] + ($event === null ? [] : ['reasonCode' => $event->reason_code, 'lifecycleEventId' => $event->id, 'freshMfa' => true])
              + ($beforeStart ? ['beforeStart' => true] : []));

            return $locked->refresh();
        }));
    }

    /** ORG.8G: the clean translation of `student_enrollments_end_within_year`. */
    private function assertEndsWithinYear(StudentEnrollment $placement, string $endsOn): void
    {
        $yearEnd = $placement->academicYear()->firstOrFail()->ends_on->toDateString();
        if ($endsOn > $yearEnd) {
            throw new InvalidEnrollmentDateRangeException('End date must not be after the academic year\'s end ('.CalendarDate::human($yearEnd).').');
        }
    }

    /**
     * Takes `SELECT ... FOR UPDATE` on the given Section rows in
     * ASCENDING ID ORDER -- the single shared serialization point
     * between this module's membership mutations and
     * App\Domain\Attendance\Application\AttendanceSubmissionService's
     * roster derivation + complete-register write (this class's
     * docblock).
     *
     * The ordering is the deadlock-avoidance mechanism, not a detail:
     * two concurrent transfers moving Students in opposite directions
     * (A->B and B->A) both request {A, B} and therefore both acquire A
     * first, so one simply waits for the other instead of each holding
     * what the other needs. Attendance takes exactly one Section lock
     * and so trivially participates in the same ordering. Duplicates
     * are collapsed -- requesting the same Section twice in one call
     * (a same-Section transfer) must not attempt to lock it twice.
     *
     * Must be called INSIDE an established TenantContext and an open
     * DB::transaction(); a row lock outside a transaction is released
     * immediately and would silently guarantee nothing.
     *
     * @param  list<string>  $sectionIds
     * @return array<string, Section> the locked Sections by id
     */
    private function lockSections(array $sectionIds): array
    {
        $ordered = array_values(array_unique($sectionIds));
        sort($ordered);

        $locked = [];
        foreach ($ordered as $sectionId) {
            $locked[$sectionId] = Section::query()->whereKey($sectionId)->lockForUpdate()->firstOrFail();
        }

        return $locked;
    }

    /**
     * ORG.7F-2 (ADR 0076 §46): the Student row, share-locked until the
     * placement commits, so a concurrent inactivation (an UPDATE) waits for
     * it, or -- having committed first -- is seen. Every placement-creating
     * path takes it BEFORE any Section or Enrollment lock (rollover
     * execution included, before its source Enrollment), never FOR UPDATE
     * (an exclusive Student lock would deadlock against the new row's own
     * foreign-key check, §40.5). Must run inside the write transaction.
     */
    public function lockStudentForPlacement(string $studentId): Student
    {
        return Student::query()->whereKey($studentId)->sharedLock()->firstOrFail();
    }

    /**
     * SIS 4.1 (MC-2): the Academic Year a new placement belongs to -- always
     * the Section's own, never a caller-supplied year -- share-locked (FOR
     * SHARE) until the placement commits. AcademicYearService's activate()
     * and close() UPDATE that row, so a close waits for this placement or,
     * having committed first, is seen; FOR SHARE stays compatible with the
     * INSERT's own foreign-key FOR KEY SHARE and with Attendance's FOR SHARE
     * (AttendanceSubmissionService, step 2). Taken after the Student and
     * BEFORE any Section lock -- Attendance's Year -> Section order.
     */
    private function lockAcademicYearForPlacement(string $academicYearId): AcademicYear
    {
        return AcademicYear::query()->whereKey($academicYearId)->sharedLock()->firstOrFail();
    }

    /**
     * ORG.7F-2 (finding F-2): the ONE placement-validity rule for every new
     * Enrollment -- an active Student, an active Section, and that Section's
     * Campus active in the same School (share-locked by ActiveCampus until
     * commit, so a Campus deactivation waits or is seen). The Section's year,
     * Campus and Grade are bound by the composite foreign key (ORG.7D-0); a
     * future year is fine (rollover), so the year is never required to be
     * the School's active one here. Data validity, never authorization.
     *
     * SIS 4.1 (MC-2): the Section's Academic Year (share-locked) is not
     * `closed` -- a `draft` year stays placeable for preparation and
     * rollover -- and the start date is a calendar date inside that year,
     * both ends inclusive. Returns the start date as `Y-m-d`.
     */
    private function assertPlaceable(Student $lockedStudent, Section $lockedSection, AcademicYear $lockedYear, string $startsOn): string
    {
        if ($lockedStudent->school_id !== $lockedSection->school_id) {
            throw new CrossSchoolEnrollmentException;
        }
        if ($lockedSection->academic_year_id !== $lockedYear->id) {
            throw new LogicException('A placement is checked against its own Section\'s Academic Year.');
        }
        if ($lockedStudent->status !== 'active') {
            throw new PlacementTargetUnavailableException(PlacementTargetUnavailableException::STUDENT_INACTIVE);
        }
        if ($lockedSection->status !== 'active') {
            throw new PlacementTargetUnavailableException(PlacementTargetUnavailableException::SECTION_INACTIVE);
        }
        if ($this->campuses->find($lockedSection->school_id, $lockedSection->campus_id) === null) {
            throw new PlacementTargetUnavailableException(PlacementTargetUnavailableException::CAMPUS_INACTIVE);
        }
        if ($lockedYear->status === 'closed') {
            throw new PlacementTargetUnavailableException(PlacementTargetUnavailableException::YEAR_CLOSED);
        }

        // Calendar dates only: no timezone conversion on either side.
        $startsOn = Carbon::parse($startsOn)->toDateString();
        $yearStart = $lockedYear->starts_on->toDateString();
        $yearEnd = $lockedYear->ends_on->toDateString();
        if ($startsOn < $yearStart || $startsOn > $yearEnd) {
            throw new InvalidEnrollmentDateRangeException("The start date must fall within Academic Year {$lockedYear->name} (".CalendarDate::human($yearStart).' to '.CalendarDate::human($yearEnd).').');
        }

        return $startsOn;
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

    private function translateUniqueViolation(UniqueConstraintViolationException $e, string $rollNumber): Throwable
    {
        return match ($e->index) {
            'student_enrollments_one_active_per_student_year' => new ActiveEnrollmentConflictException,
            'student_enrollments_school_id_academic_year_id_section_id_roll_' => new DuplicateEnrollmentRollNumberException($rollNumber),
            default => $e,
        };
    }
}
