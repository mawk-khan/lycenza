<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §5, §23): the leave request lifecycle, day portions, the
 * immutable approval snapshot, consumption and reversal.
 *
 * Calendar: Monday-Friday full, Saturday morning, Sunday off. 2026-10-07
 * (Wednesday) is a staff holiday. Today is Monday 2026-10-05.
 */
class LeaveRequestLifecycleTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 200);
        } catch (ModelNotFoundException) {
            return 'not_found';
        }
    }

    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-10-07', DayPortion::Full, 'Staff holiday', $w['admin']);
        $this->allocate($w);

        return $w;
    }

    private function balance(array $w, ?string $yearId = null): int
    {
        $rows = app(LeaveReadService::class)->balances($w['school'], $w['employment']->id, $yearId ?? $w['year']->id, $w['admin']);

        return (int) (collect($rows)->firstWhere('leaveTypeId', $w['type']->id)['availableUnits'] ?? 0);
    }

    /** @return list<array{date: string, portion: string, units: int}> */
    private function days(array $w, string $requestId): array
    {
        return array_map(fn (array $d) => ['date' => $d['date'], 'portion' => $d['portion'], 'units' => $d['units']],
            app(LeaveRequestReadService::class)->show($w['school'], $requestId, $w['admin'])['days']);
    }

    #[Test]
    public function a_request_is_submitted_approved_consumed_and_cancelled_with_append_only_evidence(): void
    {
        $w = $this->world();
        $requests = app(LeaveRequestService::class);

        // Mon-Fri with the Wednesday holiday: 4 working days = 8 units. Submission consumes nothing.
        $request = $this->submitLeave($w, '2026-10-05', '2026-10-09');
        $this->assertSame(['submitted', 8, 24], [$request->status, $request->submitted_units, $this->balance($w)]);

        $approved = $requests->approve($w['school'], $request->id, $w['admin']);
        $this->assertSame('approved', $approved->status);
        $this->assertSame(16, $this->balance($w));
        $this->assertSame([
            ['date' => '2026-10-05', 'portion' => 'full', 'units' => 2], ['date' => '2026-10-06', 'portion' => 'full', 'units' => 2],
            ['date' => '2026-10-08', 'portion' => 'full', 'units' => 2], ['date' => '2026-10-09', 'portion' => 'full', 'units' => 2],
        ], $this->days($w, $request->id), 'only chargeable working dates, the holiday excluded');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('domain_event_outbox')->where('event_type', 'leave.request.approved.v1')->count()));

        // Invalid transitions.
        $this->assertSame('LEAVE_REQUEST_NOT_SUBMITTED', $this->code(fn () => $requests->approve($w['school'], $request->id, $w['admin'])), 'a replayed approval never consumes twice');
        $this->assertSame('LEAVE_REQUEST_NOT_SUBMITTED', $this->code(fn () => $requests->withdraw($w['school'], $request->id, 'plans_changed', $w['admin'])));
        $this->assertSame('LEAVE_REQUEST_NOT_SUBMITTED', $this->code(fn () => $requests->reject($w['school'], $request->id, 'staffing_need', $w['admin'])));
        $this->assertSame(16, $this->balance($w));

        $cancelled = $requests->cancel($w['school'], $request->id, 'plans_changed', $w['admin']);
        $this->assertSame(['cancelled', 24], [$cancelled->status, $this->balance($w)], 'the reversal restores the units');
        $this->assertSame('LEAVE_REQUEST_NOT_APPROVED', $this->code(fn () => $requests->cancel($w['school'], $request->id, 'plans_changed', $w['admin'])), 'never reversed twice');
        $entries = $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $request->id)->orderBy('created_at')->get(['id', 'kind', 'units', 'reverses_entry_id']));
        $this->assertSame(['consumption', 'reversal'], $entries->pluck('kind')->all());
        $this->assertSame($entries[0]->id, $entries[1]->reverses_entry_id, 'the reversal names the exact consumption');
        $this->assertSame(['approved', 'cancelled'], $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $request->id)->orderBy('created_at')->pluck('decision')->all()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('domain_event_outbox')->where('event_type', 'leave.request.cancelled.v1')->count()));
        $this->assertSame(4, count($this->days($w, $request->id)), 'the approval evidence is preserved after cancellation');

        // A cancelled request no longer reserves its days.
        $this->assertSame('approved', $requests->approve($w['school'], $this->submitLeave($w, '2026-10-05', '2026-10-05')->id, $w['admin'])->status);

        // Evidence is immutable for the runtime role.
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_request_days')->where('leave_request_id', $request->id)->update(['units' => 1]))));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $request->id)->delete())));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $request->id)->delete())));
        $this->assertStringContainsString('leave_request_immutable', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $request->id)->update(['ends_on' => '2026-10-12']))));
        $this->assertStringContainsString('leave_request_transition_invalid', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $request->id)->update(['status' => 'submitted']))));
    }

    #[Test]
    public function rejection_and_withdrawal_close_a_submitted_request_without_touching_the_balance(): void
    {
        $w = $this->world();
        $requests = app(LeaveRequestService::class);

        $rejected = $this->submitLeave($w, '2026-10-12', '2026-10-12');
        $this->assertSame('LEAVE_REASON_INVALID', $this->code(fn () => $requests->reject($w['school'], $rejected->id, 'feeling unwell', $w['admin'])), 'closed reason codes only');
        $this->assertSame('rejected', $requests->reject($w['school'], $rejected->id, 'staffing_need', $w['admin'])->status);
        $this->assertSame('LEAVE_REQUEST_NOT_SUBMITTED', $this->code(fn () => $requests->approve($w['school'], $rejected->id, $w['admin'])), 'a rejected request never reopens');

        $withdrawn = $this->submitLeave($w, '2026-10-12', '2026-10-12');
        $this->assertSame('withdrawn', $requests->withdraw($w['school'], $withdrawn->id, 'plans_changed', $w['admin'])->status);
        $this->assertSame('LEAVE_REQUEST_NOT_APPROVED', $this->code(fn () => $requests->cancel($w['school'], $withdrawn->id, 'plans_changed', $w['admin'])));

        $this->assertSame(24, $this->balance($w));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_request_days')->count()), 'no evidence without approval');
        $this->assertSame(['staffing_need', 'plans_changed'], $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->orderBy('created_at')->pluck('reason_code')->all()));
    }

    #[Test]
    public function day_portions_are_exact_and_only_the_same_half_conflicts(): void
    {
        $w = $this->world();
        $requests = app(LeaveRequestService::class);

        $first = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'first_half');
        $second = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'second_half');
        $this->assertSame([1, 1], [$first->submitted_units, $second->submitted_units], 'opposite halves of one day both fit');
        $this->assertSame('LEAVE_REQUEST_OVERLAP', $this->code(fn () => $this->submitLeave($w, '2026-10-12', '2026-10-12', 'first_half')));
        $this->assertSame('LEAVE_REQUEST_OVERLAP', $this->code(fn () => $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full')));
        $this->assertSame('LEAVE_REQUEST_OVERLAP', $this->code(fn () => $this->submitLeave($w, '2026-10-09', '2026-10-12', 'full', 'first_half')), 'a multi-day run ending in the morning hits the morning request');

        // A multi-day request starting in the afternoon and ending in the morning: Tue pm, Wed (holiday), Thu am.
        $multi = $this->submitLeave($w, '2026-10-13', '2026-10-15', 'second_half', 'first_half');
        $requests->approve($w['school'], $multi->id, $w['admin']);
        $this->assertSame([['date' => '2026-10-13', 'portion' => 'second_half', 'units' => 1], ['date' => '2026-10-14', 'portion' => 'full', 'units' => 2], ['date' => '2026-10-15', 'portion' => 'first_half', 'units' => 1]], $this->days($w, $multi->id));

        // Saturday works only in the morning: a full Saturday charges one unit, as the first half.
        $saturday = $this->submitLeave($w, '2026-10-17', '2026-10-17');
        $requests->approve($w['school'], $saturday->id, $w['admin']);
        $this->assertSame([['date' => '2026-10-17', 'portion' => 'first_half', 'units' => 1]], $this->days($w, $saturday->id));

        // Impossible shapes are refused by the service and the database.
        $this->assertSame('LEAVE_REQUEST_SHAPE_INVALID', $this->code(fn () => $this->submitLeave($w, '2026-10-19', '2026-10-20', 'first_half', 'full')));
        $this->assertSame('LEAVE_REQUEST_SHAPE_INVALID', $this->code(fn () => $this->submitLeave($w, '2026-10-19', '2026-10-20', 'full', 'second_half')));
        $this->assertSame('LEAVE_REQUEST_SHAPE_INVALID', $this->code(fn () => $this->submitLeave($w, '2026-10-19', '2026-10-19', 'first_half', 'second_half')));
        $this->assertSame('LEAVE_REQUEST_SHAPE_INVALID', $this->code(fn () => $this->submitLeave($w, '2026-10-20', '2026-10-19')));
        $this->assertSame('LEAVE_REQUEST_NO_WORKING_DAYS', $this->code(fn () => $this->submitLeave($w, '2026-10-18', '2026-10-18')), 'a Sunday is no working time');
        $this->assertSame('LEAVE_REQUEST_NO_WORKING_DAYS', $this->code(fn () => $this->submitLeave($w, '2026-10-17', '2026-10-17', 'second_half')), 'Saturday afternoon is off');

        $wholeDays = $this->leaveType($w['school'], $w['admin'], 'EL', halfDay: false);
        $this->assertSame('LEAVE_HALF_DAY_NOT_ALLOWED', $this->code(fn () => $this->submitLeave($w, '2026-10-19', '2026-10-19', 'first_half', null, null, $wholeDays)));
    }

    #[Test]
    public function the_approval_snapshot_never_changes_with_later_calendar_policy_or_leave_year_configuration(): void
    {
        $w = $this->world();
        $request = $this->submitLeave($w, '2026-10-05', '2026-10-09');
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);
        $before = app(LeaveRequestReadService::class)->show($w['school'], $request->id, $w['admin']);

        // Every input to the calculation changes afterwards.
        app(StaffCalendarService::class)->setWeeklyPattern($w['school'], [1 => 'off', 2 => 'full', 3 => 'full', 4 => 'off', 5 => 'first_half', 6 => 'off', 7 => 'off'], $w['admin']);
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-10-06', DayPortion::Full, 'Later holiday', $w['admin']);
        $holiday = $this->inSchool($w['school'], fn () => DB::table('staff_holidays')->where('holiday_on', '2026-10-07')->value('id'));
        app(StaffCalendarService::class)->removeHoliday($w['school'], $holiday, $w['admin']);
        app(LeavePolicyService::class)->create($w['school'], $w['type']->id, ['name' => 'v2', 'annual_allocation_units' => 40, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null], $w['policy']->id, $w['admin']);
        $assignment = $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['employment']->id)->value('id'));
        app(LeavePolicyAssignmentService::class)->end($w['school'], $assignment, '2026-10-31', $w['admin']);
        app(LeaveYearService::class)->scheduleStartChange($w['school'], 1, '2028-01-01', $w['admin']);

        $after = app(LeaveRequestReadService::class)->show($w['school'], $request->id, $w['admin']);
        $this->assertSame($before['days'], $after['days'], 'dates, portions, units, year and policy version are frozen');
        $this->assertSame(8, array_sum(array_column($after['days'], 'units')));
        $this->assertSame($w['policy']->id, $after['days'][0]['leavePolicyId'], 'the policy version in force at approval, not the superseding one');
        $this->assertSame(16, $this->balance($w));
    }

    #[Test]
    public function tracked_leave_needs_an_effective_policy_and_an_open_year_and_untracked_leave_consumes_nothing(): void
    {
        $w = $this->world();
        $requests = app(LeaveRequestService::class);

        // Insufficient balance: nothing is written.
        $big = $this->submitLeave($w, '2026-10-12', '2026-10-30');
        $this->assertSame('LEAVE_BALANCE_INSUFFICIENT', $this->code(fn () => $requests->approve($w['school'], $big->id, $w['admin'])));
        $this->assertSame(['submitted', 0], [$this->inSchool($w['school'], fn () => $big->fresh()->status), $this->inSchool($w['school'], fn () => DB::table('leave_request_days')->count())]);
        $requests->withdraw($w['school'], $big->id, 'entered_in_error', $w['admin']);

        // No leave year opened for the date.
        $future = $this->submitLeave($w, '2027-04-05', '2027-04-05');
        $this->assertSame('LEAVE_YEAR_NOT_OPEN', $this->code(fn () => $requests->approve($w['school'], $future->id, $w['admin'])));

        // No policy assignment on the date (assignment starts 2026-04-01).
        $early = $this->submitLeave($w, '2026-03-30', '2026-03-30');
        app(LeaveYearService::class)->open($w['school'], '2026-03-30', $w['admin']);
        $this->assertSame('LEAVE_NO_POLICY_ASSIGNMENT', $this->code(fn () => $requests->approve($w['school'], $early->id, $w['admin'])));

        // Untracked (e.g. unpaid) leave: evidence, no ledger.
        $unpaid = $this->leaveType($w['school'], $w['admin'], 'UL', paid: false, tracked: false);
        $request = $this->submitLeave($w, '2026-11-02', '2026-11-03', 'full', null, null, $unpaid);
        $requests->approve($w['school'], $request->id, $w['admin']);
        $this->assertSame(2, count($this->days($w, $request->id)));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $request->id)->count()));
        $this->assertSame('cancelled', $requests->cancel($w['school'], $request->id, 'plans_changed', $w['admin'])->status);
    }

    #[Test]
    public function a_request_across_leave_years_and_policy_versions_is_split_by_date(): void
    {
        $w = $this->world();
        $requests = app(LeaveRequestService::class);
        $next = app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);
        $this->allocate($w, 24, $next);

        // Mon 2027-03-29 .. Fri 2027-04-02: three days in 2026-27, two in 2027-28.
        $request = $this->submitLeave($w, '2027-03-29', '2027-04-02');
        $requests->approve($w['school'], $request->id, $w['admin']);
        $consumptions = $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $request->id)->where('kind', 'consumption')->pluck('units', 'leave_year_id')->all());
        $this->assertSame([$w['year']->id => 6, $next->id => 4], $consumptions);
        $this->assertSame([18, 20], [$this->balance($w), $this->balance($w, $next->id)]);

        // A policy change inside a request: each date carries the version in force.
        $assignment = $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['employment']->id)->value('id'));
        app(LeavePolicyAssignmentService::class)->end($w['school'], $assignment, '2026-11-04', $w['admin']);
        $v2 = $this->leavePolicy($w['school'], $w['type'], $w['admin'], ['name' => 'v2']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $v2->id, '2026-11-05', null, $w['admin']);
        $split = $this->submitLeave($w, '2026-11-04', '2026-11-05');
        $requests->approve($w['school'], $split->id, $w['admin']);
        $policies = array_column(app(LeaveRequestReadService::class)->show($w['school'], $split->id, $w['admin'])['days'], 'leavePolicyId', 'date');
        $this->assertSame(['2026-11-04' => $w['policy']->id, '2026-11-05' => $v2->id], $policies);
    }
}
