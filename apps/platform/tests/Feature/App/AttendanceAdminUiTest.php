<?php

namespace Tests\Feature\App;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 -- the session-authenticated administrative Attendance UI.
 * Deliberately covers the full workflow end to end (date -> class ->
 * roster -> complete register -> read back -> correct) plus the
 * capability boundary on every page.
 */
class AttendanceAdminUiTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_full_take_a_register_workflow_works(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $b = $this->enrollStudent($w['section'], '2', '2026-06-01');

        // 1-2. Date + class selection reads CURRENT timetable state.
        $take = $this->actor($w)->get('/app/attendance/take?attendance_date='.self::MONDAY);
        $take->assertOk();
        $take->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Attendance/Take')
            ->where('attendanceDate', self::MONDAY)
            ->has('classes', 1)
            ->where('classes.0.timetableEntryId', $w['entry']->id)
            ->where('classes.0.alreadySubmitted', false)
            ->where('statuses', ['present', 'absent', 'late', 'excused'])
        );

        // 3. Roster preview for the chosen class.
        $withRoster = $this->actor($w)->get(
            '/app/attendance/take?attendance_date='.self::MONDAY."&timetable_entry_id={$w['entry']->id}",
        );
        $withRoster->assertInertia(fn (AssertableInertia $page) => $page
            ->has('roster', 2)
            ->where('rosterError', null)
        );

        // 4-5. Mark everyone and submit the complete register.
        $this->actor($w)->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'records' => $this->registerPayload([$a->id => 'present', $b->id => 'absent']),
        ])->assertRedirect();

        $session = $this->inSchool($w['school'], fn () => AttendanceSession::query()->firstOrFail());

        // 6. Read the submitted historical register back.
        $show = $this->actor($w)->get("/app/attendance/{$session->id}");
        $show->assertOk();
        $show->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Attendance/Show')
            ->where('session.sectionCode', $w['section']->code)
            ->where('session.periodStartTime', '09:00:00')
            ->where('session.dayOfWeek', 1)
            ->where('session.timetableEntryId', $w['entry']->id)
            ->has('session.records', 2)
            ->where('canManage', true)
        );

        // The class is no longer offered for a second register.
        $this->actor($w)->get('/app/attendance/take?attendance_date='.self::MONDAY)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classes.0.alreadySubmitted', true));

        // 7. Correct one status with expected-status semantics.
        $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('student_enrollment_id', $b->id)->firstOrFail());

        $this->actor($w)->post("/app/attendance/records/{$record->id}/correct", [
            'expected_status' => 'absent', 'new_status' => 'excused',
        ])->assertRedirect();

        $this->assertSame('excused', $this->inSchool($w['school'], fn () => $record->fresh()->status));

        // The index lists it.
        $this->actor($w)->get('/app/attendance')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Attendance/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.recordCount', 2)
        );
    }

    #[Test]
    public function a_stale_correction_from_the_ui_is_refused(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $service = app(AttendanceSubmissionService::class);
        $session = $this->inSchool($w['school'], fn () => $service->guarded(
            fn () => DB::transaction(fn () => $service->submit(
                $w['school'], $w['entry']->id, self::MONDAY,
                $this->registerPayload([$a->id => 'absent']), $w['actor'],
            ))
        ));
        $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->firstOrFail());

        $this->actor($w)->post("/app/attendance/records/{$record->id}/correct", [
            'expected_status' => 'absent', 'new_status' => 'present',
        ])->assertRedirect();

        // A second tab still showing `absent`.
        $this->actor($w)->post("/app/attendance/records/{$record->id}/correct", [
            'expected_status' => 'absent', 'new_status' => 'late',
        ])->assertSessionHasErrors('new_status');

        $this->assertSame('present', $this->inSchool($w['school'], fn () => $record->fresh()->status));
    }

    #[Test]
    public function an_incomplete_register_is_refused_by_the_ui_endpoint(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->enrollStudent($w['section'], '2', '2026-06-01');

        $this->actor($w)->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'records' => $this->registerPayload([$a->id => 'present']),
        ])->assertSessionHasErrors('timetable_entry_id');

        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function an_ambiguous_roster_is_surfaced_on_the_page_rather_than_crashing(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

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

        $this->actor($w)->get('/app/attendance/take?attendance_date='.self::MONDAY."&timetable_entry_id={$w['entry']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rosterError')->has('roster', 0));
    }

    #[Test]
    public function a_member_without_attendance_view_cannot_reach_any_attendance_page(): void
    {
        $w = $this->attendanceWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->actor($w, $outsider)->get('/app/attendance')->assertForbidden();
        $this->actor($w, $outsider)->get('/app/attendance/take')->assertForbidden();
        $this->actor($w, $outsider)->get('/app/attendance/roster?timetable_entry_id='.$w['entry']->id.'&attendance_date='.self::MONDAY)->assertForbidden();
        $this->actor($w, $outsider)->post('/app/attendance', [])->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_registers_but_cannot_take_or_correct_one(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $service = app(AttendanceSubmissionService::class);
        $session = $this->inSchool($w['school'], fn () => $service->guarded(
            fn () => DB::transaction(fn () => $service->submit(
                $w['school'], $w['entry']->id, self::MONDAY,
                $this->registerPayload([$a->id => 'absent']), $w['actor'],
            ))
        ));
        $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()->firstOrFail());

        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);

        $this->actor($w, $viewer)->get('/app/attendance')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->get("/app/attendance/{$session->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->get('/app/attendance/take')->assertForbidden();
        $this->actor($w, $viewer)->post("/app/attendance/records/{$record->id}/correct", [
            'expected_status' => 'absent', 'new_status' => 'present',
        ])->assertForbidden();
    }
}
