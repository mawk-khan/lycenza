<?php

namespace App\Domain\Attendance\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Attendance\Application\Exceptions\AcademicYearNotActiveException;
use App\Domain\Attendance\Application\Exceptions\AttendanceDateInFutureException;
use App\Domain\Attendance\Application\Exceptions\AttendanceDateOutsideAcademicYearException;
use App\Domain\Attendance\Application\Exceptions\AttendanceDateWeekdayMismatchException;
use App\Domain\Attendance\Application\Exceptions\AttendanceSessionAlreadySubmittedException;
use App\Domain\Attendance\Application\Exceptions\DuplicateEnrollmentInRegisterException;
use App\Domain\Attendance\Application\Exceptions\EmptyRosterException;
use App\Domain\Attendance\Application\Exceptions\OverlappingAttendanceSessionException;
use App\Domain\Attendance\Application\Exceptions\RegisterDoesNotMatchRosterException;
use App\Domain\Attendance\Application\Exceptions\SectionSlotAlreadySubmittedException;
use App\Domain\Attendance\Application\Exceptions\TimetableEntryNotSchedulableException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 0H.2: the ONE sanctioned writer of `attendance_sessions` and of
 * NEW `attendance_records` rows. Nothing else -- no controller, no job,
 * no console command, no query-builder insert -- may create either
 * (guarded by `Tests\Feature\Attendance\AttendanceArchitectureGuardTest`).
 * This is not stylistic: the historical wall-clock overlap invariant
 * (section "Time overlap" below) is enforced by THIS service under a
 * Section row lock, so a second writer would silently bypass an
 * invariant no database constraint expresses.
 *
 * Authorization-neutral for Tier 1: the controller authorizes
 * `attendance.manage` before reaching this class, and BEFORE the
 * idempotency guard is consulted (CLAUDE.md rule 32). TCH.4 (ADR 0063
 * section 11) adds an optional Tier 2 AttendanceWriteGuard: when a teacher
 * submits, the guard holds the ActingEmployee and the covering
 * TeachingAssignment (FOR SHARE) as step 0, before any lock below.
 *
 * GLOBAL LOCK ORDER (never inverted):
 *
 *   0. (Tier 2 only) School, membership, User, Employee, EmploymentRecord,
 *      TeachingAssignment -- FOR SHARE, through the guard
 *   1. TimetableEntry   -- SELECT ... FOR UPDATE on (id, school_id)
 *   2. AcademicYear     -- SELECT ... FOR UPDATE
 *   3. Section          -- SELECT ... FOR UPDATE  (shared with SIS)
 *   4. StudentEnrollment rows, ascending id order
 *   5. attendance_sessions / attendance_records writes
 *
 * Step 1 is what makes the snapshot coherent: every immutable context
 * column is read off the entry WHILE it is locked, so a concurrent
 * `TimetableScheduleService::update()`/`deactivate()` either happens
 * entirely before or entirely after -- never producing a torn snapshot
 * built from two different versions of the entry.
 *
 * Step 3 is the synchronization point shared with
 * App\Domain\Students\Application\StudentEnrollmentService, which now
 * takes the SAME Section lock before any membership mutation. Holding
 * it across roster derivation, exact-set validation and the write is
 * what makes "complete register" actually mean complete: a concurrent
 * enroll/transfer/withdrawal cannot land between deriving the roster
 * and writing it.
 *
 * Step 4's ascending-id ordering (never request order) is a stable
 * global order, so two concurrent submissions can never deadlock
 * against each other on overlapping rosters.
 *
 * TIME OVERLAP. `attendance_sessions_section_slot_unique`
 * (school, section, period_id, date) stops a duplicate register for
 * one Period IDENTITY, but not for one wall-clock SLOT: a retired
 * Period P and a newer Period Q can both denote 09:00-10:00 under
 * different ids, so two registers could otherwise both claim that hour
 * for one Section on one date. This service therefore also rejects any
 * candidate whose half-open `[period_start_time, period_end_time)`
 * interval overlaps an already-submitted Session for the same School +
 * Section + date. Adjacent intervals (09:00-10:00 then 10:00-11:00) do
 * NOT overlap and are allowed. This is a service-level invariant under
 * the Section lock -- exactly the mechanism
 * App\Domain\Timetable\Application\TimetablePeriodService already uses
 * for its own Period-range non-overlap rule -- deliberately NOT a
 * PostgreSQL EXCLUDE constraint, which would require enabling
 * btree_gist for this single rule (CLAUDE.md rule 2). Because every
 * sanctioned submission for a Section acquires that same Section lock
 * before checking, two concurrent submissions cannot both pass.
 */
class AttendanceSubmissionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StudentEnrollmentRosterReadService $roster,
    ) {}

    /**
     * Submits ONE complete register.
     *
     * The caller supplies only `$timetableEntryId`, `$attendanceDate`
     * and `$records`. Every element of the historical class context --
     * AcademicYear, Campus, GradeLevel, Section, SubjectOffering,
     * teacher, Period, and the Period's wall-clock times -- is derived
     * server-side from the LOCKED TimetableEntry. There is no
     * client-controlled duplication of any derived value and therefore
     * no client-controlled path to a mismatch (CLAUDE.md rule 19's
     * principle).
     *
     * MUST be called inside the caller's own DB::transaction() when the
     * caller also needs IdempotencyGuard::completeWithin() to commit
     * atomically with the register (the API controller does exactly
     * that). `$inTransaction` therefore defaults to assuming an
     * enclosing transaction is already open.
     *
     * @param  list<array{student_enrollment_id: string, status: string}>  $records
     */
    public function submit(
        School $school,
        string $timetableEntryId,
        string $attendanceDate,
        array $records,
        User $actor,
        ?AttendanceWriteGuard $guard = null,
    ): AttendanceSession {
        $this->assertNotInFuture($attendanceDate);
        $this->assertNoDuplicateEnrollments($records);

        // 0. Tier 2 only: identity and ownership for the class the entry
        //    names on this date, held before the entry lock (the ADR 0063
        //    section 20 order). The entry only NAMES the class -- its
        //    teacher_id authorizes nothing.
        if ($guard !== null) {
            $peek = TimetableEntry::query()->where('id', $timetableEntryId)->where('school_id', $school->id)->firstOrFail();

            try {
                $guard->beforeSubmit($school, $peek->section_id, $peek->subject_offering_id, Carbon::parse($attendanceDate)->toDateString());
            } catch (ModelNotFoundException) {
                // An unowned class answers exactly like an unknown entry
                // (ADR 0063 section 18): same status, same body.
                throw (new ModelNotFoundException)->setModel(TimetableEntry::class);
            }
        }

        // 1. TimetableEntry -- locked first, and every snapshot value is
        //    read off this locked row.
        $entry = TimetableEntry::query()
            ->where('id', $timetableEntryId)
            ->where('school_id', $school->id)
            ->lockForUpdate()
            ->firstOrFail();

        // An entry repointed at another class between the unlocked read and
        // the lock is re-checked against the class it names NOW.
        if ($guard !== null
            && ($peek->section_id !== $entry->section_id || $peek->subject_offering_id !== $entry->subject_offering_id)) {
            $guard->beforeSubmit($school, $entry->section_id, $entry->subject_offering_id, Carbon::parse($attendanceDate)->toDateString());
        }

        if (! $entry->isActive()) {
            throw new TimetableEntryNotSchedulableException;
        }

        $isoWeekday = (int) Carbon::parse($attendanceDate)->isoWeekday();
        if ($isoWeekday !== (int) $entry->day_of_week) {
            throw new AttendanceDateWeekdayMismatchException((int) $entry->day_of_week, $isoWeekday);
        }

        // The Period is resolved through the LOCKED entry, so the
        // wall-clock times snapshotted below are the ones genuinely in
        // force for this entry at submission.
        $period = TimetablePeriod::query()
            ->where('id', $entry->period_id)
            ->where('school_id', $school->id)
            ->firstOrFail();

        // 2. AcademicYear -- a SHARED lock (FOR SHARE), deliberately
        //    NOT lockForUpdate(). Attendance only needs to stop the
        //    year being closed or re-dated underneath this submission;
        //    it has no reason to exclude other readers.
        //
        //    Using FOR UPDATE here caused a REAL, reproducible deadlock
        //    (SQLSTATE 40P01), found by
        //    `AttendanceConcurrencyTest::attendance_submission_and_a_concurrent_enrollment_serialize_on_the_shared_section_lock`
        //    before this code ever shipped. Every INSERT into
        //    `student_enrollments` takes an implicit FOR KEY SHARE lock
        //    on its referenced `academic_years` row, so:
        //      - this service held academic_years FOR UPDATE and waited
        //        for the Section row, while
        //      - a concurrent StudentEnrollmentService::enroll() held
        //        that Section row and waited for FOR KEY SHARE on the
        //        same academic_years row.
        //    FOR SHARE resolves it: it is compatible with the FK's FOR
        //    KEY SHARE (so an enrollment INSERT never blocks on it),
        //    while still conflicting with the FOR NO KEY UPDATE that
        //    AcademicYearService::activate()/close()'s status UPDATE
        //    takes -- so the invariant this lock exists for is fully
        //    preserved. The Section lock (step 3) remains exclusive:
        //    that one IS the serialization point, and both sides
        //    acquire it in the same order, so it can only ever wait,
        //    never cycle.
        $year = AcademicYear::query()
            ->where('id', $entry->academic_year_id)
            ->where('school_id', $school->id)
            ->sharedLock()
            ->firstOrFail();

        if ($year->status !== 'active') {
            throw new AcademicYearNotActiveException($year->status);
        }

        $date = Carbon::parse($attendanceDate)->toDateString();
        if ($date < $year->starts_on->toDateString() || $date > $year->ends_on->toDateString()) {
            throw new AttendanceDateOutsideAcademicYearException($date);
        }

        // 3. Section -- the shared serialization point with
        //    StudentEnrollmentService. Held for the rest of this method.
        Section::query()->whereKey($entry->section_id)->lockForUpdate()->firstOrFail();

        $this->assertNoExistingSession($school, $entry, $date);
        $this->assertNoOverlappingSession($school, $entry->section_id, $date, $period->start_time, $period->end_time);

        // 4. Roster: derive, lock every qualifying Enrollment row in
        //    ascending id order, then RE-derive so the authoritative
        //    exact-set comparison is made against locked rows.
        $rosterArgs = [
            $school->id, $entry->academic_year_id, $entry->campus_id,
            $entry->grade_level_id, $entry->section_id, $date,
        ];

        foreach ($this->roster->qualifyingEnrollmentIdsAsOf(...$rosterArgs) as $enrollmentId) {
            StudentEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->firstOrFail();
        }

        $members = $this->roster->membersAsOf(...$rosterArgs);

        if ($members->isEmpty()) {
            throw new EmptyRosterException;
        }

        $statusByEnrollment = $this->assertExactSet($members->pluck('studentEnrollmentId')->all(), $records);

        // 5. Writes.
        $session = AttendanceSession::query()->create([
            'school_id' => $school->id,
            'timetable_entry_id' => $entry->id,
            'attendance_date' => $date,
            'academic_year_id' => $entry->academic_year_id,
            'campus_id' => $entry->campus_id,
            'grade_level_id' => $entry->grade_level_id,
            'section_id' => $entry->section_id,
            'subject_offering_id' => $entry->subject_offering_id,
            'teacher_id' => $entry->teacher_id,
            'period_id' => $entry->period_id,
            'period_start_time' => $period->start_time,
            'period_end_time' => $period->end_time,
            'submitted_by_user_id' => $actor->id,
            'submitted_at' => now(),
        ]);

        $this->insertRecords($session, $statusByEnrollment);

        $this->audit->school($school, 'attendance.session.submitted', actor: $actor, subject: $session, metadata: [
            // Bounded metadata only: ids, the date, and aggregate
            // counts. Never the roster itself and never a Student name
            // -- an audit row must not become a second copy of
            // Sensitive-tier register content.
            'sessionId' => $session->id,
            'timetableEntryId' => $entry->id,
            'sectionId' => $entry->section_id,
            'attendanceDate' => $date,
            'recordCount' => count($statusByEnrollment),
            'statusCounts' => $this->statusCounts($statusByEnrollment),
        ]);

        return $session;
    }

    /**
     * Bulk insert with an EXPLICIT column list -- the structural
     * context columns are copied from the just-created Session, never
     * from client input and never through mass assignment (they are
     * deliberately absent from AttendanceRecord::$fillable). Both of
     * `attendance_records`' composite FKs then pin these same physical
     * values to the Session AND to the StudentEnrollment, so a
     * wrong-Section/year/campus/grade row is structurally impossible.
     *
     * @param  array<string, string>  $statusByEnrollment
     */
    private function insertRecords(AttendanceSession $session, array $statusByEnrollment): void
    {
        $now = now();
        $rows = [];

        foreach ($statusByEnrollment as $enrollmentId => $status) {
            $rows[] = [
                'id' => (string) new UuidV7,
                'school_id' => $session->school_id,
                'attendance_session_id' => $session->id,
                'student_enrollment_id' => $enrollmentId,
                'academic_year_id' => $session->academic_year_id,
                'campus_id' => $session->campus_id,
                'grade_level_id' => $session->grade_level_id,
                'section_id' => $session->section_id,
                'status' => $status,
                'corrected_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AttendanceRecord::query()->insert($rows);
    }

    /**
     * Complete-register discipline. The submitted set must equal the
     * authoritative roster EXACTLY -- no omission, no extra, no partial
     * save, and never an implicit default-to-present for a Student the
     * caller forgot. Status values are validated by the request layer
     * and, authoritatively, by the database's own
     * `attendance_records_status_check`.
     *
     * @param  list<string>  $rosterEnrollmentIds
     * @param  list<array{student_enrollment_id: string, status: string}>  $records
     * @return array<string, string> enrollment id => status
     */
    private function assertExactSet(array $rosterEnrollmentIds, array $records): array
    {
        $submitted = [];
        foreach ($records as $record) {
            $submitted[$record['student_enrollment_id']] = $record['status'];
        }

        $missing = array_values(array_diff($rosterEnrollmentIds, array_keys($submitted)));
        $unexpected = array_values(array_diff(array_keys($submitted), $rosterEnrollmentIds));

        if ($missing !== [] || $unexpected !== []) {
            throw new RegisterDoesNotMatchRosterException($missing, $unexpected);
        }

        // Preserve the roster's own deterministic order so records are
        // inserted in a stable sequence regardless of payload order.
        $ordered = [];
        foreach ($rosterEnrollmentIds as $id) {
            $ordered[$id] = $submitted[$id];
        }

        return $ordered;
    }

    /**
     * Both uniqueness rules are checked here for a clean typed error,
     * but the DATABASE's own unique indexes are the authoritative
     * concurrency guarantee -- `submitViaController()`'s caller
     * translates a UniqueConstraintViolationException that slips past
     * these reads under a genuine race (CLAUDE.md rule 30: never an
     * existence check alone).
     */
    private function assertNoExistingSession(School $school, TimetableEntry $entry, string $date): void
    {
        $entryDateTaken = AttendanceSession::query()
            ->where('school_id', $school->id)
            ->where('timetable_entry_id', $entry->id)
            ->whereDate('attendance_date', $date)
            ->exists();

        if ($entryDateTaken) {
            throw new AttendanceSessionAlreadySubmittedException;
        }

        $slotTaken = AttendanceSession::query()
            ->where('school_id', $school->id)
            ->where('section_id', $entry->section_id)
            ->where('period_id', $entry->period_id)
            ->whereDate('attendance_date', $date)
            ->exists();

        if ($slotTaken) {
            throw new SectionSlotAlreadySubmittedException;
        }
    }

    /**
     * Half-open interval overlap: [a1, a2) and [b1, b2) overlap iff
     * a1 < b2 AND b1 < a2 -- the identical expression
     * TimetablePeriodService::assertNoOverlap() uses. Adjacent
     * intervals therefore never collide. MUST be called while the
     * Section row lock is held (see this class's docblock); that lock,
     * not this query, is what makes it safe under concurrency.
     */
    private function assertNoOverlappingSession(School $school, string $sectionId, string $date, string $startTime, string $endTime): void
    {
        $conflict = AttendanceSession::query()
            ->where('school_id', $school->id)
            ->where('section_id', $sectionId)
            ->whereDate('attendance_date', $date)
            ->where('period_start_time', '<', $endTime)
            ->where('period_end_time', '>', $startTime)
            ->first();

        if ($conflict !== null) {
            throw new OverlappingAttendanceSessionException($conflict->id);
        }
    }

    /**
     * Evaluated against the platform's UTC application date -- the same
     * clock (`now()`) every other module treats as authoritative. Today
     * itself is always allowed: a register is normally taken on the day
     * of the class.
     */
    private function assertNotInFuture(string $attendanceDate): void
    {
        $date = Carbon::parse($attendanceDate)->toDateString();

        if ($date > now()->toDateString()) {
            throw new AttendanceDateInFutureException($date);
        }
    }

    /**
     * @param  list<array{student_enrollment_id: string, status: string}>  $records
     */
    private function assertNoDuplicateEnrollments(array $records): void
    {
        $seen = [];
        foreach ($records as $record) {
            $id = $record['student_enrollment_id'];
            if (isset($seen[$id])) {
                throw new DuplicateEnrollmentInRegisterException($id);
            }
            $seen[$id] = true;
        }
    }

    /**
     * @param  array<string, string>  $statusByEnrollment
     * @return array<string, int>
     */
    private function statusCounts(array $statusByEnrollment): array
    {
        $counts = array_fill_keys(AttendanceRecord::STATUSES, 0);

        foreach ($statusByEnrollment as $status) {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Translates the two database uniqueness guarantees into the same
     * typed domain conflicts `assertNoExistingSession()` raises. The
     * pre-checks above give a clean error in the ordinary case; THIS is
     * what covers the genuine race two concurrent submissions can still
     * win past those reads (CLAUDE.md rule 30). Mirrors
     * TimetableScheduleService::guarded()'s established shape.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function guarded(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'attendance_sessions_entry_date_unique')) {
                throw new AttendanceSessionAlreadySubmittedException;
            }

            if (str_contains($message, 'attendance_sessions_section_slot_unique')) {
                throw new SectionSlotAlreadySubmittedException;
            }

            throw $e;
        }
    }
}
