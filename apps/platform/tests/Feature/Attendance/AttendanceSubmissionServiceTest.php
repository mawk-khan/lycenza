<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
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
use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2: the authoritative submission path -- eligibility,
 * complete-register discipline, the historical roster predicate, the
 * immutable snapshot, and every uniqueness/overlap conflict.
 */
class AttendanceSubmissionServiceTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function service(): AttendanceSubmissionService
    {
        return app(AttendanceSubmissionService::class);
    }

    /**
     * Every test calls the service inside a transaction, exactly like
     * the controller does (the controller additionally completes the
     * idempotency record in that same transaction).
     */
    private function submit(array $world, array $records, ?string $date = null): AttendanceSession
    {
        return $this->inSchool($world['school'], fn () => $this->service()->guarded(
            fn () => DB::transaction(fn () => $this->service()->submit(
                $world['school'],
                $world['entry']->id,
                $date ?? self::MONDAY,
                $records,
                $world['actor'],
            ))
        ));
    }

    #[Test]
    public function a_complete_register_is_submitted_with_an_immutable_class_snapshot(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $b = $this->enrollStudent($w['section'], '2', '2026-06-01');

        $session = $this->submit($w, $this->registerPayload([
            $a->id => 'present',
            $b->id => 'absent',
        ]));

        // Snapshot equals the source entry's context AT SUBMISSION.
        $this->assertSame($w['entry']->id, $session->timetable_entry_id);
        $this->assertSame($w['year']->id, $session->academic_year_id);
        $this->assertSame($w['campus']->id, $session->campus_id);
        $this->assertSame($w['grade']->id, $session->grade_level_id);
        $this->assertSame($w['section']->id, $session->section_id);
        $this->assertSame($w['offering']->id, $session->subject_offering_id);
        $this->assertSame($w['teacher']->id, $session->teacher_id);
        $this->assertSame($w['period']->id, $session->period_id);
        $this->assertSame('09:00:00', $session->period_start_time);
        $this->assertSame('10:00:00', $session->period_end_time);
        $this->assertSame($w['actor']->id, $session->submitted_by_user_id);
        $this->assertNotNull($session->submitted_at);
        // Weekday is derived, never stored.
        $this->assertSame(1, $session->dayOfWeek());

        $records = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->get());
        $this->assertCount(2, $records);
        $this->assertSame('present', $records->firstWhere('student_enrollment_id', $a->id)->status);
        $this->assertSame('absent', $records->firstWhere('student_enrollment_id', $b->id)->status);

        // Structural context columns are copied from the Session.
        foreach ($records as $record) {
            $this->assertSame($session->academic_year_id, $record->academic_year_id);
            $this->assertSame($session->campus_id, $record->campus_id);
            $this->assertSame($session->grade_level_id, $record->grade_level_id);
            $this->assertSame($session->section_id, $record->section_id);
            $this->assertNull($record->corrected_at);
        }
    }

    #[Test]
    public function submission_is_audited_with_bounded_metadata_only(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $session = $this->submit($w, $this->registerPayload([$a->id => 'late']));

        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $w['school']->id)
            ->where('event_type', 'attendance.session.submitted')
            ->firstOrFail());

        $this->assertSame($session->id, $event->metadata['sessionId']);
        $this->assertSame($w['entry']->id, $event->metadata['timetableEntryId']);
        $this->assertSame(self::MONDAY, $event->metadata['attendanceDate']);
        $this->assertSame(1, $event->metadata['recordCount']);
        $this->assertSame(1, $event->metadata['statusCounts']['late']);
        $this->assertSame($w['actor']->id, $event->actor_user_id);

        // No roster list, no Student identity, no names.
        $encoded = json_encode($event->metadata);
        $this->assertStringNotContainsString($a->id, $encoded);
        $this->assertArrayNotHasKey('records', $event->metadata);
    }

    #[Test]
    public function an_inactive_timetable_entry_cannot_be_used_for_a_new_register(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)
            ->deactivate($w['entry'], $w['actor']));

        $this->expectException(TimetableEntryNotSchedulableException::class);
        $this->submit($w, []);
    }

    #[Test]
    public function a_date_whose_weekday_does_not_match_the_entry_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->expectException(AttendanceDateWeekdayMismatchException::class);
        $this->submit($w, [], '2026-08-25'); // Tuesday
    }

    #[Test]
    public function a_future_date_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->expectException(AttendanceDateInFutureException::class);
        $this->submit($w, [], now()->addWeek()->next('Monday')->toDateString());
    }

    #[Test]
    public function a_date_outside_the_academic_year_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');

        // A Monday before the AcademicYear's starts_on (2026-06-01).
        $this->expectException(AttendanceDateOutsideAcademicYearException::class);
        $this->submit($w, [], '2026-05-25');
    }

    #[Test]
    public function a_new_register_is_rejected_once_the_academic_year_is_closed(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->inSchool($w['school'], fn () => $w['year']->forceFill(['status' => 'closed'])->save());

        $this->expectException(AcademicYearNotActiveException::class);
        $this->submit($w, []);
    }

    #[Test]
    public function an_empty_roster_is_rejected_and_creates_no_session(): void
    {
        $w = $this->attendanceWorld();

        try {
            $this->submit($w, []);
            $this->fail('Expected EmptyRosterException.');
        } catch (EmptyRosterException) {
            // expected
        }

        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function an_omitted_roster_member_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->enrollStudent($w['section'], '2', '2026-06-01');

        $this->expectException(RegisterDoesNotMatchRosterException::class);
        $this->submit($w, $this->registerPayload([$a->id => 'present']));
    }

    #[Test]
    public function an_extra_enrollment_from_another_section_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $other = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        $outsider = $this->enrollStudent($other, '1', '2026-06-01');

        $this->expectException(RegisterDoesNotMatchRosterException::class);
        $this->submit($w, $this->registerPayload([$a->id => 'present', $outsider->id => 'present']));
    }

    #[Test]
    public function a_duplicate_enrollment_id_in_the_payload_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->expectException(DuplicateEnrollmentInRegisterException::class);
        $this->submit($w, [
            ['student_enrollment_id' => $a->id, 'status' => 'present'],
            ['student_enrollment_id' => $a->id, 'status' => 'absent'],
        ]);
    }

    #[Test]
    public function a_second_submission_for_the_same_entry_and_date_is_a_conflict(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->submit($w, $this->registerPayload([$a->id => 'present']));

        $this->expectException(AttendanceSessionAlreadySubmittedException::class);
        $this->submit($w, $this->registerPayload([$a->id => 'absent']));
    }

    #[Test]
    public function a_second_entry_reusing_the_same_section_period_and_date_is_a_conflict(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->submit($w, $this->registerPayload([$a->id => 'present']));

        // Free the slot, then recreate an entry on the SAME
        // Section/Period/day -- possible because every timetable
        // double-booking index is scoped WHERE status='active'.
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->deactivate($w['entry'], $w['actor']));
        $replacement = $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->create(
            $w['school'], $w['offering'], $w['section'], $w['teacher'], null, $w['period'], 1, $w['actor'],
        ));

        $this->expectException(SectionSlotAlreadySubmittedException::class);
        $this->inSchool($w['school'], fn () => $this->service()->guarded(
            fn () => DB::transaction(fn () => $this->service()->submit(
                $w['school'], $replacement->id, self::MONDAY,
                $this->registerPayload([$a->id => 'absent']), $w['actor'],
            ))
        ));
    }

    #[Test]
    public function an_overlapping_wall_clock_interval_for_the_same_section_and_date_is_rejected(): void
    {
        $w = $this->attendanceWorld(); // 09:00-10:00
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->submit($w, $this->registerPayload([$a->id => 'present']));

        // A DIFFERENT Period id and a DIFFERENT TimetableEntry, whose
        // interval overlaps the already-submitted register's.
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->deactivate($w['entry'], $w['actor']));
        $overlapping = $this->createTimetablePeriod($w['school'], ['start_time' => '09:30:00', 'end_time' => '10:30:00', 'code' => 'P2']);
        $entry2 = $this->createTimetableEntry($w['offering'], $w['section'], $w['teacher'], $overlapping, ['day_of_week' => 1]);

        $this->expectException(OverlappingAttendanceSessionException::class);
        $this->inSchool($w['school'], fn () => $this->service()->guarded(
            fn () => DB::transaction(fn () => $this->service()->submit(
                $w['school'], $entry2->id, self::MONDAY,
                $this->registerPayload([$a->id => 'absent']), $w['actor'],
            ))
        ));
    }

    #[Test]
    public function an_adjacent_wall_clock_interval_for_the_same_section_and_date_is_allowed(): void
    {
        $w = $this->attendanceWorld(); // 09:00-10:00
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->submit($w, $this->registerPayload([$a->id => 'present']));

        $adjacent = $this->createTimetablePeriod($w['school'], ['start_time' => '10:00:00', 'end_time' => '11:00:00', 'code' => 'P2']);
        $entry2 = $this->createTimetableEntry($w['offering'], $w['section'], $w['teacher'], $adjacent, ['day_of_week' => 1]);

        $second = $this->inSchool($w['school'], fn () => $this->service()->guarded(
            fn () => DB::transaction(fn () => $this->service()->submit(
                $w['school'], $entry2->id, self::MONDAY,
                $this->registerPayload([$a->id => 'absent']), $w['actor'],
            ))
        ));

        $this->assertSame('10:00:00', $second->period_start_time);
        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function ambiguous_overlapping_historical_placements_are_rejected(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        // A second, terminal placement for the SAME Student in the SAME
        // Section whose interval also contains the attendance date.
        // Only reachable through bad/backdated data -- the partial
        // unique index constrains ACTIVE rows only.
        $this->inSchool($w['school'], fn () => StudentEnrollment::query()->create([
            'school_id' => $w['school']->id,
            'student_id' => $a->student_id,
            'academic_year_id' => $w['year']->id,
            'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id,
            'roll_number' => '99',
            'status' => 'withdrawn',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-01-01',
        ]));

        $this->expectException(AmbiguousHistoricalEnrollmentException::class);
        $this->submit($w, $this->registerPayload([$a->id => 'present']));
    }

    #[Test]
    public function a_student_enrolled_after_the_attendance_date_is_not_on_the_register(): void
    {
        $w = $this->attendanceWorld();
        $early = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $late = $this->enrollStudent($w['section'], '2', '2026-10-01'); // after MONDAY

        $session = $this->submit($w, $this->registerPayload([$early->id => 'present']));

        $ids = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->pluck('student_enrollment_id')->all());
        $this->assertSame([$early->id], $ids);
        $this->assertNotContains($late->id, $ids);
    }

    #[Test]
    public function a_terminal_placement_whose_interval_contains_the_date_still_appears(): void
    {
        $w = $this->attendanceWorld();
        $stayer = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $leaver = $this->enrollStudent($w['section'], '2', '2026-06-01');

        // Withdrawn AFTER the attendance date -- historically present.
        app(StudentEnrollmentService::class)->withdraw($leaver, '2026-10-01', $w['actor']);

        $session = $this->submit($w, $this->registerPayload([
            $stayer->id => 'present', $leaver->id => 'absent',
        ]));

        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->count()));
    }

    #[Test]
    public function a_placement_that_ended_before_the_date_is_excluded(): void
    {
        $w = $this->attendanceWorld();
        $stayer = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $gone = $this->enrollStudent($w['section'], '2', '2026-06-01');

        // ends_on is INCLUSIVE, so an end one day before MONDAY excludes.
        app(StudentEnrollmentService::class)->withdraw($gone, '2026-08-30', $w['actor']);

        $session = $this->submit($w, $this->registerPayload([$stayer->id => 'present']));

        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->count()));
    }

    #[Test]
    public function ends_on_is_inclusive_on_the_boundary_day(): void
    {
        $w = $this->attendanceWorld();
        $boundary = $this->enrollStudent($w['section'], '1', '2026-06-01');
        app(StudentEnrollmentService::class)->withdraw($boundary, self::MONDAY, $w['actor']);

        $session = $this->submit($w, $this->registerPayload([$boundary->id => 'present']));

        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->count()));
    }
}
