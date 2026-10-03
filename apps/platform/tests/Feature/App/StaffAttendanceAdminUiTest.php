<?php

namespace Tests\Feature\App;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24.15): the session-authenticated Staff Attendance
 * administration pages -- the daily register and an employment's history.
 *
 * - Both pages need `hr.staff_attendance.view`; forms are offered only to
 *   `hr.staff_attendance.manage`, and the services refuse anyone else.
 * - Domain refusals are form errors; another School's ids never leak.
 */
class StaffAttendanceAdminUiTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function actor(array $w, ?User $user = null): static
    {
        return $this->actingAs($user ?? $w['clerk'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_pages_render_for_a_viewer_and_offer_forms_only_to_a_manager(): void
    {
        $w = $this->attendanceWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view']);

        $this->actor($w)->get('/app/staff-attendance?date=2026-09-28')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/StaffAttendance/Register')->where('canManage', true)->where('register.date', '2026-09-28')->has('register.rows', 1)
            ->where('register.rows.0.day.summary', 'unrecorded'));
        $this->actor($w, $viewer)->get('/app/staff-attendance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->where('register.date', '2026-10-05'));
        $this->actor($w, $viewer)->get("/app/staff-attendance/history?employment_record_id={$w['employment']->id}&from=2026-09-28&to=2026-10-04")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/StaffAttendance/History')->has('history.days', 7));

        foreach ([['hr.staff_attendance.manage'], ['hr.leave.view', 'hr.leave.manage'], ['attendance.teacher', 'attendance.manage']] as $capabilities) {
            $other = $this->createUserWithCapabilities($w['school'], $capabilities);
            $this->actor($w, $other)->get('/app/staff-attendance')->assertForbidden();
        }

        // A viewer cannot write through the pages; the service refuses.
        $this->actor($w, $viewer)->post('/app/staff-attendance/records', ['employment_record_id' => $w['employment']->id, 'date' => '2026-09-28', 'first_half' => 'present', 'second_half' => 'present'])->assertForbidden();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_records')->count()));

        $this->actor($w)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewStaffAttendance', true));
        $this->actor($w, $this->createUserWithCapabilities($w['school'], ['hr.leave.view']))->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewStaffAttendance', false));
    }

    #[Test]
    public function record_bulk_and_correct_through_the_pages_with_domain_refusals_as_form_errors(): void
    {
        $w = $this->attendanceWorld();
        $other = $this->currentEmployment($w['school']);

        $this->actor($w)->from('/app/staff-attendance')->post('/app/staff-attendance/records', ['employment_record_id' => $w['employment']->id, 'date' => '2026-09-28', 'first_half' => 'present', 'second_half' => 'absent'])
            ->assertRedirect('/app/staff-attendance')->assertSessionHasNoErrors();
        $this->actor($w)->post('/app/staff-attendance/register', ['date' => '2026-09-29', 'items' => [
            ['employment_record_id' => $w['employment']->id, 'first_half' => 'present', 'second_half' => 'present'],
            ['employment_record_id' => $other->id, 'first_half' => 'absent', 'second_half' => 'absent'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_records')->count()));

        $record = $this->inSchool($w['school'], fn () => DB::table('staff_attendance_records')->where('attendance_date', '2026-09-28')->first());
        $this->actor($w)->post("/app/staff-attendance/records/{$record->id}/corrections", ['expected_version' => 1, 'first_half' => 'present', 'second_half' => 'present', 'reason_code' => 'late_information'])->assertSessionHasNoErrors();
        $this->actor($w)->post("/app/staff-attendance/records/{$record->id}/corrections", ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'late_information'])
            ->assertSessionHasErrors(['attendance' => 'The record changed since you read it; reload and correct again. (STAFF_ATTENDANCE_VERSION_STALE)']);
        $this->actor($w)->post('/app/staff-attendance/records', ['employment_record_id' => $w['employment']->id, 'date' => '2026-10-04', 'first_half' => 'present', 'second_half' => null])
            ->assertSessionHasErrors('attendance');

        $foreign = $this->attendanceWorld();
        $this->actor($w)->post('/app/staff-attendance/records', ['employment_record_id' => $foreign['employment']->id, 'date' => '2026-09-28', 'first_half' => 'present', 'second_half' => 'present'])
            ->assertSessionHasErrors(['attendance' => 'Choose a record of this School.']);
        $this->actor($w)->get("/app/staff-attendance/history?employment_record_id={$foreign['employment']->id}")->assertNotFound();

        $this->actor($w)->get("/app/staff-attendance/history?employment_record_id={$w['employment']->id}&from=2026-09-28&to=2026-09-30")
            ->assertInertia(fn (AssertableInertia $page) => $page->has("corrections.{$record->id}", 1));
    }
}
