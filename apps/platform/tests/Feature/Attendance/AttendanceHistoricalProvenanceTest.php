<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceCorrectionService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2: proves that NOTHING upstream can rewrite an already
 * submitted register's historical meaning -- not a TimetableEntry edit,
 * not a Period retiming, not a backdated SIS change, and not a
 * Subject/teacher rename. Each test also asserts the upstream mutation
 * itself SUCCEEDS: Attendance must never become a reverse dependency
 * that blocks its parents.
 */
class AttendanceHistoricalProvenanceTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function submitted(array $periodTimes = ['start_time' => '09:00:00', 'end_time' => '10:00:00']): array
    {
        $w = $this->attendanceWorld($periodTimes);
        $w['enrollment'] = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $service = app(AttendanceSubmissionService::class);
        $w['session'] = $this->inSchool($w['school'], fn () => $service->guarded(
            fn () => DB::transaction(fn () => $service->submit(
                $w['school'], $w['entry']->id, self::MONDAY,
                $this->registerPayload([$w['enrollment']->id => 'absent']), $w['actor'],
            ))
        ));

        return $w;
    }

    /**
     * @return array<string, mixed> the register exactly as the API renders it
     */
    private function register(array $w): array
    {
        $response = $this->actingAs($w['actor'])
            ->withHeader('X-School-Id', $w['school']->id)
            ->getJson("/api/v1/schools/{$w['school']->id}/attendance-sessions/{$w['session']->id}");
        $response->assertOk();

        return $response->json('data');
    }

    #[Test]
    public function a_submitted_register_survives_every_mutable_timetable_entry_change(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        // Build a completely different, valid target context.
        $otherSubject = $this->createSubject($w['school'], ['code' => 'SCI']);
        $otherOffering = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $otherSubject, [
            'is_required' => true, 'status' => 'active',
        ]);
        $otherSection = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        $otherTeacher = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $otherRoom = $this->createRoom($w['campus'], ['status' => 'active']);
        $otherPeriod = $this->createTimetablePeriod($w['school'], [
            'start_time' => '14:00:00', 'end_time' => '15:00:00', 'code' => 'P9',
        ]);

        // The Timetable edit MUST succeed -- Attendance is not a reverse
        // dependency. Every mutable field changes at once, including the
        // three derived context columns and the weekday.
        $updated = $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->update(
            $w['entry'], $otherOffering, $otherSection, $otherTeacher, $otherRoom, $otherPeriod, 3, $w['actor'],
        ));
        $this->assertSame($otherSection->id, $updated->section_id);
        $this->assertSame(3, (int) $updated->day_of_week);

        // Deactivating it must also succeed, and must not hide history.
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->deactivate($updated, $w['actor']));

        $after = $this->register($w);

        $this->assertSame($before, $after, 'A historical register must be byte-identical after any TimetableEntry mutation.');
        $this->assertSame($w['section']->id, $after['sectionId']);
        $this->assertSame($w['offering']->id, $after['subjectOfferingId']);
        $this->assertSame($w['teacher']->id, $after['teacherId']);
        $this->assertSame($w['period']->id, $after['periodId']);
        $this->assertSame('09:00:00', $after['periodStartTime']);
        $this->assertSame(1, $after['dayOfWeek'], 'Weekday is derived from attendance_date, never from the entry.');
        $this->assertArrayNotHasKey('roomId', $after, 'Room is outside Attendance historical identity.');
        $this->assertSame($w['entry']->id, $after['timetableEntryId'], 'Provenance id is echoed, never dereferenced.');
    }

    #[Test]
    public function a_period_retiming_never_re_times_a_historical_register(): void
    {
        $w = $this->submitted(); // 09:00-10:00
        $before = $this->register($w);
        $this->assertSame('09:00:00', $before['periodStartTime']);
        $this->assertSame('10:00:00', $before['periodEndTime']);

        // The SUPPORTED way to retime a Period: free it from every
        // active TimetableEntry first (TimetablePeriodReferencedException's
        // own docblock prescribes exactly this), then change the times.
        $this->inSchool($w['school'], fn () => app(TimetableScheduleService::class)->deactivate($w['entry'], $w['actor']));
        $this->inSchool($w['school'], fn () => app(TimetablePeriodService::class)->update(
            $w['period'], ['start_time' => '10:00:00', 'end_time' => '11:00:00'], $w['actor'],
        ));

        // The parent genuinely moved -- this test is not vacuous.
        $this->assertSame('10:00:00', $this->inSchool(
            $w['school'], fn () => TimetablePeriod::query()->findOrFail($w['period']->id)->start_time,
        ));

        $after = $this->register($w);
        $this->assertSame('09:00:00', $after['periodStartTime']);
        $this->assertSame('10:00:00', $after['periodEndTime']);
        $this->assertSame($w['period']->id, $after['periodId']);
    }

    #[Test]
    public function a_period_rename_propagates_as_a_current_label_while_times_stay_frozen(): void
    {
        $w = $this->submitted();

        // Non-temporal Period fields are unconditionally mutable -- no
        // lock, no referenced-entry guard.
        $this->inSchool($w['school'], fn () => app(TimetablePeriodService::class)->update(
            $w['period'], ['code' => 'RENAMED', 'name' => 'Renamed Period'], $w['actor'],
        ));

        $after = $this->register($w);
        $this->assertSame('RENAMED', $after['periodCode'], 'Period code/name are CURRENT labels.');
        $this->assertSame('Renamed Period', $after['periodName']);
        $this->assertSame('09:00:00', $after['periodStartTime'], 'Historical times stay frozen.');
        $this->assertSame('10:00:00', $after['periodEndTime']);
    }

    #[Test]
    public function a_subject_rename_propagates_under_an_unchanged_subject_identity(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        $subjectId = $before['subjectId'];
        // A dedicated actor holding only the Academic Structure
        // capability -- the Attendance actor deliberately does not.
        $academicsActor = $this->createUserWithCapabilities($w['school'], ['academics.subjects.manage']);
        $this->actingAs($academicsActor)->withHeader('X-School-Id', $w['school']->id)
            ->patchJson("/api/v1/schools/{$w['school']->id}/subjects/{$subjectId}", [
                'name' => 'Renamed Subject',
            ])->assertOk();

        $after = $this->register($w);
        $this->assertSame($before['subjectOfferingId'], $after['subjectOfferingId']);
        $this->assertSame($subjectId, $after['subjectId'], 'Subject IDENTITY is unchanged.');
        $this->assertSame('Renamed Subject', $after['subjectName'], 'The current name propagates.');
    }

    #[Test]
    public function a_teacher_name_correction_propagates_under_an_unchanged_employee_identity(): void
    {
        $w = $this->submitted();

        $hrActor = $this->createUserWithCapabilities($w['school'], ['hr.employees.manage']);
        $this->inSchool($w['school'], fn () => app(EmployeeService::class)->update(
            $w['teacher'], ['full_name' => 'Corrected Teacher Name'], $hrActor,
        ));

        $after = $this->register($w);
        $this->assertSame($w['teacher']->id, $after['teacherId'], 'Employee IDENTITY is unchanged.');
        $this->assertSame('Corrected Teacher Name', $after['teacherName']);

        // Sensitive-tier minimization: no broader HR field ever appears.
        foreach (['workEmail', 'work_email', 'workPhone', 'work_phone', 'employeeNumber'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $after);
        }
    }

    #[Test]
    public function backdated_sis_changes_never_rewrite_a_submitted_register(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        $target = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);

        // A BACKDATED transfer whose effective date precedes the
        // register's own attendance_date.
        $moved = app(StudentEnrollmentService::class)->transferPlacement(
            $w['enrollment'], $target, '7', '2026-08-03', $w['actor'],
        );
        $this->assertSame($target->id, $moved->section_id, 'The SIS mutation itself must succeed.');
        $this->assertSame('transferred', $this->inSchool($w['school'], fn () => $w['enrollment']->fresh()->status));

        $after = $this->register($w);
        $this->assertSame($before, $after, 'Attendance is authoritative once submitted.');
        $this->assertSame($w['enrollment']->id, $after['records'][0]['studentEnrollmentId']);
        $this->assertSame('absent', $after['records'][0]['status']);
    }

    #[Test]
    public function a_correction_still_succeeds_after_a_backdated_sis_change(): void
    {
        $w = $this->submitted();
        $target = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        app(StudentEnrollmentService::class)->transferPlacement($w['enrollment'], $target, '7', '2026-08-03', $w['actor']);

        $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $w['session']->id)->firstOrFail());

        $corrected = app(AttendanceCorrectionService::class)
            ->correct($w['school'], $record->id, 'absent', 'present', $w['actor']);

        $this->assertSame('present', $corrected->status);
        $this->assertNotNull($corrected->corrected_at);
    }

    #[Test]
    public function a_backdated_withdrawal_never_rewrites_a_submitted_register(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        app(StudentEnrollmentService::class)->withdraw($w['enrollment'], '2026-08-03', $w['actor']);

        $this->assertSame($before, $this->register($w));
    }

    #[Test]
    public function a_backdated_cancellation_never_rewrites_a_submitted_register(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        app(StudentEnrollmentService::class)->cancel($w['enrollment'], '2026-08-03', $w['actor']);

        $this->assertSame($before, $this->register($w));
    }

    #[Test]
    public function a_prospective_transfer_never_rewrites_a_submitted_register(): void
    {
        $w = $this->submitted();
        $before = $this->register($w);

        $target = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        app(StudentEnrollmentService::class)->transferPlacement($w['enrollment'], $target, '7', '2026-09-28', $w['actor']);

        $this->assertSame($before, $this->register($w));
    }
}
