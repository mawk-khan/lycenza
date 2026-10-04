<?php

namespace Tests\Feature\StaffSelfService;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.6): own staff attendance is the HRX.3 per-half
 * composition for the acting EmploymentRecord, READ ONLY, without internal
 * record ids/versions or correction history.
 */
class MyStaffAttendanceTest extends TestCase
{
    use CreatesSelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    /** @return array<string, mixed> date => day */
    private function days(array $w, User $user, string $from, string $to): array
    {
        return collect($this->as($user)->getJson("/api/v1/schools/{$w['school']->id}/my/staff-attendance?from={$from}&to={$to}")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data.days'))->keyBy('date')->all();
    }

    #[Test]
    public function i_see_my_own_halves_with_leave_holiday_and_off_day_composed_and_nothing_internal(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $colleague = $this->selfMember($w);
        $this->recordAttendance($w, '2026-09-28', 'present', 'absent', $me['employment']);
        $this->recordAttendance($w, '2026-09-28', 'absent', 'absent', $colleague['employment']);
        $this->recordAttendance($w, '2026-09-29', 'absent', 'absent', $me['employment']);
        $leave = $this->submitLeave($w, '2026-09-29', '2026-09-29', employment: $me['employment']);
        app(LeaveRequestService::class)->approve($w['school'], $leave->id, $w['admin']);
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-09-30', DayPortion::FirstHalf, 'Founders morning', $w['admin']);

        $days = $this->days($w, $me['user'], '2026-09-28', '2026-10-04');
        $this->assertCount(7, $days);
        $this->assertSame(['present', 'absent', 'half_day_absent'], [$days['2026-09-28']['firstHalf']['state'], $days['2026-09-28']['secondHalf']['state'], $days['2026-09-28']['summary']], 'my record, not my colleague\'s');
        $this->assertSame(['leave', 'absent', 'CL leave'], [$days['2026-09-29']['firstHalf']['state'], $days['2026-09-29']['firstHalf']['recorded'], $days['2026-09-29']['firstHalf']['leave']['leaveTypeName']], 'leave over the preserved absence');
        $this->assertSame(['holiday', 'unrecorded'], [$days['2026-09-30']['firstHalf']['state'], $days['2026-09-30']['secondHalf']['state']]);
        $this->assertSame('off_day', $days['2026-10-04']['summary']);
        $this->assertArrayNotHasKey('record', $days['2026-09-28'], 'no internal record id or version');

        // Cancelling the leave reveals the absence again.
        app(LeaveRequestService::class)->cancel($w['school'], $leave->id, 'plans_changed', $w['admin']);
        $this->assertSame('absent', $this->days($w, $me['user'], '2026-09-29', '2026-09-29')['2026-09-29']['summary']);
    }

    #[Test]
    public function the_view_is_clipped_to_my_employment_read_only_and_never_names_anyone(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $this->inSchool($w['school'], fn () => DB::table('employment_records')->where('id', $me['employment']->id)->update(['starts_on' => '2026-10-01']));

        $days = $this->days($w, $me['user'], '2026-09-28', '2026-10-04');
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'], array_keys($days), 'nothing before my employment started');

        $base = "/api/v1/schools/{$w['school']->id}/my/staff-attendance";
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->as($me['user'])->{$method}($base, ['first_half' => 'present'])->assertStatus(405);
        }
        // There is no parameter to name another employment: one is simply ignored, never honoured.
        $other = $this->selfMember($w);
        $this->recordAttendance($w, '2026-10-01', 'present', 'present', $other['employment']);
        $this->assertSame('unrecorded', $this->as($me['user'])->getJson("{$base}?from=2026-10-01&to=2026-10-01&employment_record_id={$other['employment']->id}")->assertOk()->json('data.days.0.summary'));
        $this->as($me['user'])->getJson("{$base}?from=2026-01-01&to=2026-09-30")->assertStatus(422)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_RANGE_INVALID');
    }

    #[Test]
    public function capability_and_ownership_are_both_required_and_the_administrative_register_stays_closed(): void
    {
        $w = $this->attendanceWorld();
        $me = $this->selfMember($w);
        $noEmployee = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.self']);
        $noCapability = $this->staffMember($w['school'], ['hr.leave.self']);
        $base = "/api/v1/schools/{$w['school']->id}";

        $this->as($noEmployee)->getJson("{$base}/my/staff-attendance")->assertNotFound();
        $this->as($noCapability['user'])->getJson("{$base}/my/staff-attendance")->assertForbidden();
        $this->as($me['user'])->getJson("{$base}/staff-attendance/register?date=2026-09-28")->assertForbidden();
        $this->as($me['user'])->postJson("{$base}/staff-attendance/records", ['employment_record_id' => $me['employment']->id, 'date' => '2026-09-28', 'first_half' => 'present', 'second_half' => 'present'])->assertForbidden();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_records')->count()));
    }
}
