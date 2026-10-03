<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §8, §24.3, §24.5): Leave and Staff Attendance meet only
 * through application contracts, half-day exact:
 * - approved leave blocks attendance on the same half, never the other half;
 * - recorded PRESENT blocks approving leave over it; recorded ABSENT does not
 *   and stays untouched underneath;
 * - cancelling the leave makes the underlying evidence effective again;
 * - neither domain ever writes the other's rows.
 */
class StaffAttendanceLeaveReconciliationTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function refusal(callable $command): string
    {
        try {
            $command();
        } catch (StaffAttendanceException|LeaveException $e) {
            return $e->errorCode();
        }

        return 'accepted';
    }

    private function day(array $w, string $date, $actor = null): array
    {
        return app(StaffAttendanceReadService::class)->register($w['school'], $date, $actor ?? $w['clerk'])['rows'][0]['day'];
    }

    #[Test]
    public function full_day_leave_blocks_both_halves_and_reads_as_on_leave(): void
    {
        $w = $this->attendanceWorld();
        $this->approvedLeave($w, '2026-09-28', '2026-09-28');

        $this->assertSame('STAFF_ATTENDANCE_ON_LEAVE', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-28', 'present', null)));
        $this->assertSame('STAFF_ATTENDANCE_ON_LEAVE', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-28', null, 'absent')));
        $day = $this->day($w, '2026-09-28');
        $this->assertSame(['leave', 'leave', 'on_leave'], [$day['firstHalf']['state'], $day['secondHalf']['state'], $day['summary']]);
    }

    #[Test]
    public function half_day_leave_leaves_the_opposite_half_independently_recordable(): void
    {
        $w = $this->attendanceWorld();
        $this->approvedLeave($w, '2026-09-28', '2026-09-28', 'first_half');
        $this->approvedLeave($w, '2026-09-29', '2026-09-29', 'first_half');
        $this->approvedLeave($w, '2026-09-30', '2026-09-30', 'second_half');

        $this->assertSame('STAFF_ATTENDANCE_ON_LEAVE', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-28', 'present', 'present')));
        $this->recordAttendance($w, '2026-09-28', null, 'present');
        $this->recordAttendance($w, '2026-09-29', null, 'absent');
        $this->recordAttendance($w, '2026-09-30', 'present', null);

        $monday = $this->day($w, '2026-09-28');
        $this->assertSame(['leave', 'present', 'mixed'], [$monday['firstHalf']['state'], $monday['secondHalf']['state'], $monday['summary']], 'leave + present is never forced into one status');
        $tuesday = $this->day($w, '2026-09-29');
        $this->assertSame(['leave', 'absent', 'mixed'], [$tuesday['firstHalf']['state'], $tuesday['secondHalf']['state'], $tuesday['summary']]);
        $wednesday = $this->day($w, '2026-09-30');
        $this->assertSame(['present', 'leave'], [$wednesday['firstHalf']['state'], $wednesday['secondHalf']['state']]);
    }

    #[Test]
    public function recorded_presence_blocks_approval_of_leave_over_the_same_half_only(): void
    {
        $w = $this->attendanceWorld();
        $this->recordAttendance($w, '2026-09-28', 'present', 'absent');

        $sameHalf = $this->submitLeave($w, '2026-09-28', '2026-09-28', 'first_half');
        $this->assertSame('LEAVE_ATTENDANCE_PRESENT', $this->refusal(fn () => app(LeaveRequestService::class)->approve($w['school'], $sameHalf->id, $w['admin'])));
        $this->assertSame('submitted', $this->inSchool($w['school'], fn () => $sameHalf->refresh()->status));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_request_days')->where('leave_request_id', $sameHalf->id)->count()), 'the refused approval wrote no evidence');
        app(LeaveRequestService::class)->withdraw($w['school'], $sameHalf->id, 'entered_in_error', $w['admin']);

        // The absent second half does not block; the absence stays underneath.
        $opposite = $this->submitLeave($w, '2026-09-28', '2026-09-28', 'second_half');
        $this->assertSame('approved', app(LeaveRequestService::class)->approve($w['school'], $opposite->id, $w['admin'])->status);

        // A multi-day request whose chargeable days include the present half is refused as a whole.
        $this->recordAttendance($w, '2026-09-30', 'present', 'present');
        $span = $this->submitLeave($w, '2026-09-29', '2026-10-01');
        $this->assertSame('LEAVE_ATTENDANCE_PRESENT', $this->refusal(fn () => app(LeaveRequestService::class)->approve($w['school'], $span->id, $w['admin'])));
    }

    #[Test]
    public function absence_recorded_before_an_approval_stays_untouched_and_reappears_when_the_leave_is_cancelled(): void
    {
        $w = $this->attendanceWorld();
        $record = $this->recordAttendance($w, '2026-09-28', 'absent', 'absent');
        $before = $this->inSchool($w['school'], fn () => (array) DB::table('staff_attendance_records')->where('id', $record->id)->first());

        $leave = $this->approvedLeave($w, '2026-09-28', '2026-09-28');
        $during = $this->day($w, '2026-09-28');
        $this->assertSame(['leave', 'absent', 'on_leave'], [$during['firstHalf']['state'], $during['firstHalf']['recorded'], $during['summary']], 'the effective view shows leave over the preserved absence');
        $this->assertSame($record->id, $during['record']['id']);

        app(LeaveRequestService::class)->cancel($w['school'], $leave->id, 'plans_changed', $w['admin']);
        $after = $this->day($w, '2026-09-28');
        $this->assertSame(['absent', 'absent', 'absent'], [$after['firstHalf']['state'], $after['secondHalf']['state'], $after['summary']], 'cancellation makes the original evidence effective again');

        $this->assertSame($before, $this->inSchool($w['school'], fn () => (array) DB::table('staff_attendance_records')->where('id', $record->id)->first()), 'approval and cancellation never wrote attendance');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_corrections')->count()));
    }

    #[Test]
    public function attendance_writes_never_touch_leave_rows(): void
    {
        $w = $this->attendanceWorld();
        $leave = $this->approvedLeave($w, '2026-09-28', '2026-09-28', 'first_half');
        $leaveTables = ['leave_requests', 'leave_request_days', 'leave_decisions', 'leave_ledger_entries'];
        $snapshot = fn () => $this->inSchool($w['school'], fn () => collect($leaveTables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('school_id', $w['school']->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all());
        $before = $snapshot();

        $record = $this->recordAttendance($w, '2026-09-28', null, 'present');
        $this->correctAttendance($w, $record, null, 'absent', 'late_information');
        $this->recordAttendance($w, '2026-09-29', 'present', 'present');

        $this->assertSame($before, $snapshot());
        $this->assertSame('approved', $this->inSchool($w['school'], fn () => $leave->refresh()->status));
    }

    #[Test]
    public function the_register_shows_leave_minimally_and_identifies_it_only_to_a_leave_reader(): void
    {
        $w = $this->attendanceWorld();
        $leave = $this->approvedLeave($w, '2026-09-28', '2026-09-28');

        $clerkView = $this->day($w, '2026-09-28');
        $this->assertTrue($clerkView['firstHalf']['onLeave']);
        $this->assertNull($clerkView['firstHalf']['leave'], 'without hr.leave.view: "on leave" and nothing more');

        $both = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view', 'hr.leave.view']);
        $leaveReader = $this->day($w, '2026-09-28', $both);
        $this->assertSame(['leaveRequestId' => $leave->id, 'leaveTypeName' => 'CL leave'], $leaveReader['firstHalf']['leave'], 'the request id and type name only -- never reason, decision, approver, policy or balance');
    }
}
