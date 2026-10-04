<?php

namespace Tests\Feature\App;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25): the session-authenticated Staff Self-Service pages --
 * My Leave, My Staff Attendance (read only), My Payslips -- with one
 * navigation link per OWN capability, an "unavailable" state for an actor
 * without an ActingEmployee, and the private 404 for an unowned payslip or
 * request page.
 */
class StaffSelfServiceUiTest extends TestCase
{
    use CreatesSelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function navigation_follows_each_own_capability_separately(): void
    {
        $w = $this->attendanceWorld();
        foreach ([
            [['hr.leave.self'], [true, false, false]],
            [['hr.staff_attendance.self'], [false, true, false]],
            [['payroll.payslips.self'], [false, false, true]],
            [self::SELF_CAPABILITIES, [true, true, true]],
        ] as [$capabilities, [$leave, $attendance, $payslips]]) {
            $user = $this->staffMember($w['school'], $capabilities)['user'];
            $this->actor($w, $user)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nav.canUseMyLeave', $leave)->where('nav.canUseMyStaffAttendance', $attendance)->where('nav.canUseMyPayslips', $payslips)
                ->where('nav.canViewLeave', false)->where('nav.canViewStaffAttendance', false));
            $this->actor($w, $user)->get('/app/my-leave')->assertStatus($leave ? 200 : 403);
            $this->actor($w, $user)->get('/app/my-staff-attendance')->assertStatus($attendance ? 200 : 403);
            $this->actor($w, $user)->get('/app/my-payslips')->assertStatus($payslips ? 200 : 403);
        }
    }

    #[Test]
    public function the_pages_show_only_my_data_and_an_unavailable_state_without_an_acting_employee(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $colleague = $this->selfMember($w);
        $this->recordAttendance($w, '2026-09-28', 'present', 'absent', $me['employment']);
        $run = $this->payrollRun($w['school'], [$me['employment'], $colleague['employment']]);
        $this->submitLeave($w, '2026-10-19', '2026-10-19', employment: $colleague['employment']);

        $this->actor($w, $me['user'])->get('/app/my-leave')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/My/Leave')->where('available', true)->has('requests', 0)->where('overview.balances.0.availableUnits', 24));
        $this->actor($w, $me['user'])->get('/app/my-staff-attendance?from=2026-09-28&to=2026-09-28')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/My/StaffAttendance')->where('attendance.days.0.summary', 'half_day_absent'));
        $this->actor($w, $me['user'])->get('/app/my-payslips')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/My/Payslips')->has('payslips', 1)->where('payslips.0.employmentRecordId', $me['employment']->id));
        $this->actor($w, $me['user'])->get("/app/my-payslips/{$run}/{$me['employment']->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Payroll/Payslips/Show')->where('back.href', '/app/my-payslips')->where('payslip.employeeId', $me['employment']->employee_id));
        $this->actor($w, $me['user'])->get("/app/my-payslips/{$run}/{$colleague['employment']->id}")->assertNotFound();
        $this->actor($w, $me['user'])->get('/app/my-payslips/'.Str::uuid().'/'.$me['employment']->id)->assertNotFound();

        $orphan = $this->createUserWithCapabilities($w['school'], self::SELF_CAPABILITIES);
        foreach (['/app/my-leave' => 'App/My/Leave', '/app/my-staff-attendance' => 'App/My/StaffAttendance', '/app/my-payslips' => 'App/My/Payslips'] as $path => $component) {
            $this->actor($w, $orphan)->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where('available', false)->missing('requests')->missing('payslips')->missing('attendance'));
        }
    }

    #[Test]
    public function i_submit_withdraw_and_cancel_through_the_page_and_never_reach_someone_elses_request(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $colleague = $this->selfMember($w);

        $this->actor($w, $me['user'])->post('/app/my-leave/requests', ['leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'full', 'ends_on' => '2026-10-12', 'end_portion' => 'full'])->assertSessionHasNoErrors();
        $mine = $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('employment_record_id', $me['employment']->id)->value('id'));
        $this->actor($w, $me['user'])->get("/app/my-leave/requests/{$mine}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('App/My/LeaveRequest'));
        $this->actor($w, $me['user'])->post("/app/my-leave/requests/{$mine}/withdraw", ['reason_code' => 'plans_changed'])->assertSessionHasNoErrors();

        $approved = $this->submitLeave($w, '2026-10-19', '2026-10-19', employment: $me['employment']);
        app(LeaveRequestService::class)->approve($w['school'], $approved->id, $w['admin']);
        $this->actor($w, $me['user'])->post("/app/my-leave/requests/{$approved->id}/cancel", ['reason_code' => 'plans_changed'])->assertSessionHasNoErrors();
        $this->assertSame(['withdrawn', 'cancelled'], $this->inSchool($w['school'], fn () => [DB::table('leave_requests')->where('id', $mine)->value('status'), DB::table('leave_requests')->where('id', $approved->id)->value('status')]));

        $theirs = $this->submitLeave($w, '2026-10-12', '2026-10-12', employment: $colleague['employment'])->id;
        $this->actor($w, $me['user'])->get("/app/my-leave/requests/{$theirs}")->assertNotFound();
        $this->actor($w, $me['user'])->post("/app/my-leave/requests/{$theirs}/withdraw", ['reason_code' => 'plans_changed'])->assertNotFound();
        $this->actor($w, $me['user'])->post('/app/my-leave/requests', ['leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-13', 'start_portion' => 'full', 'ends_on' => '2026-10-13', 'end_portion' => 'full', 'employment_record_id' => $colleague['employment']->id])
            ->assertSessionHasErrors('employment_record_id');
        $this->assertSame('submitted', $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $theirs)->value('status')));
    }
}
