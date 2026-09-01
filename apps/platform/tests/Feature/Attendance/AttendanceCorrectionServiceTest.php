<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceCorrectionNoOpException;
use App\Domain\Attendance\Application\Exceptions\AttendanceRecordStatusChangedException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2: expected-status compare-and-swap correction, and the
 * deliberate absence of any current-state revalidation that would make
 * a legitimate historical correction impossible.
 */
class AttendanceCorrectionServiceTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['enrollment'] = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $service = app(AttendanceSubmissionService::class);
        $w['session'] = $this->inSchool($w['school'], fn () => $service->guarded(
            fn () => DB::transaction(fn () => $service->submit(
                $w['school'], $w['entry']->id, self::MONDAY,
                $this->registerPayload([$w['enrollment']->id => 'absent']), $w['actor'],
            ))
        ));
        $w['record'] = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $w['session']->id)->firstOrFail());

        return $w;
    }

    private function correct(array $w, string $expected, string $new, ?string $recordId = null): AttendanceRecord
    {
        return app(AttendanceCorrectionService::class)->correct(
            $w['school'], $recordId ?? $w['record']->id, $expected, $new, $w['actor'],
        );
    }

    #[Test]
    public function a_correction_with_the_matching_expected_status_succeeds_and_is_audited(): void
    {
        $w = $this->world();

        $corrected = $this->correct($w, 'absent', 'present');

        $this->assertSame('present', $corrected->status);
        $this->assertNotNull($corrected->corrected_at);

        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'attendance.record.corrected')->firstOrFail());
        $this->assertSame($w['record']->id, $event->metadata['recordId']);
        $this->assertSame($w['session']->id, $event->metadata['sessionId']);
        $this->assertSame('absent', $event->metadata['previousStatus']);
        $this->assertSame('present', $event->metadata['newStatus']);
        $this->assertSame($w['actor']->id, $event->actor_user_id);
    }

    #[Test]
    public function a_correction_whose_expected_status_is_stale_is_refused(): void
    {
        $w = $this->world();
        $this->correct($w, 'absent', 'late');

        // A second admin still believes the record says `absent`.
        $this->expectException(AttendanceRecordStatusChangedException::class);
        $this->correct($w, 'absent', 'present');
    }

    #[Test]
    public function only_the_first_of_two_corrections_using_the_same_expected_status_succeeds(): void
    {
        $w = $this->world();

        $first = $this->correct($w, 'absent', 'present');
        $this->assertSame('present', $first->status);

        try {
            $this->correct($w, 'absent', 'excused');
            $this->fail('Expected the stale correction to be refused.');
        } catch (AttendanceRecordStatusChangedException $e) {
            $this->assertSame('absent', $e->expected);
            $this->assertSame('present', $e->actual);
        }

        $this->assertSame('present', $this->inSchool($w['school'], fn () => $w['record']->fresh()->status));
    }

    #[Test]
    public function a_no_op_correction_is_rejected(): void
    {
        $w = $this->world();

        $this->expectException(AttendanceCorrectionNoOpException::class);
        $this->correct($w, 'absent', 'absent');
    }

    #[Test]
    public function a_correction_still_succeeds_after_the_academic_year_is_closed(): void
    {
        $w = $this->world();
        $this->inSchool($w['school'], fn () => $w['year']->forceFill(['status' => 'closed'])->save());

        $this->assertSame('present', $this->correct($w, 'absent', 'present')->status);
    }

    #[Test]
    public function a_correction_still_succeeds_after_the_timetable_entry_is_deactivated(): void
    {
        $w = $this->world();
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->deactivate($w['entry'], $w['actor']));

        $this->assertSame('late', $this->correct($w, 'absent', 'late')->status);
    }

    #[Test]
    public function a_correction_still_succeeds_after_the_student_is_withdrawn(): void
    {
        $w = $this->world();
        app(StudentEnrollmentService::class)->withdraw($w['enrollment'], '2026-09-01', $w['actor']);

        $this->assertSame('excused', $this->correct($w, 'absent', 'excused')->status);
    }

    #[Test]
    public function a_correction_never_touches_the_session_or_the_structural_context(): void
    {
        $w = $this->world();
        $before = $this->inSchool($w['school'], fn () => $w['session']->fresh()->toArray());

        $this->correct($w, 'absent', 'present');

        $after = $this->inSchool($w['school'], fn () => $w['session']->fresh()->toArray());
        $this->assertSame($before, $after, 'A record correction must never mutate the Session header.');

        $record = $this->inSchool($w['school'], fn () => $w['record']->fresh());
        $this->assertSame($w['session']->section_id, $record->section_id);
        $this->assertSame($w['session']->academic_year_id, $record->academic_year_id);
        $this->assertSame($w['enrollment']->id, $record->student_enrollment_id);
    }
}
