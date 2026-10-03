<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Infrastructure\LeaveYear;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §23.8, §23.9): the explicit leave-year close and the
 * reconciliation of a cancellation after the close.
 *
 * Policy: carry forward up to 10 units, carried units recorded to expire 90
 * days into the next year. The 2026-27 year has ended (today 2027-04-10).
 */
class LeaveYearCloseTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-04-10 10:00:00');
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
        }
    }

    /** @return array<string, mixed> */
    private function world(int $allocation = 24): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w, $allocation);
        $w['next'] = app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);

        return $w;
    }

    private function balance(array $w, LeaveYear $year): int
    {
        $rows = app(LeaveReadService::class)->balances($w['school'], $w['employment']->id, $year->id, $w['admin']);

        return (int) (collect($rows)->firstWhere('leaveTypeId', $w['type']->id)['availableUnits'] ?? 0);
    }

    /** Approve a two-working-day (4-unit) request starting on a Monday of 2026-27. */
    private function approvedLeave(array $w, string $monday): string
    {
        $request = $this->submitLeave($w, $monday, date('Y-m-d', strtotime($monday.' +1 day')));
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);

        return $request->id;
    }

    private function close(array $w): void
    {
        app(LeaveYearCloseService::class)->execute($w['school'], $w['year']->id, $w['admin']);
    }

    #[Test]
    public function the_close_carries_up_to_the_cap_lapses_the_rest_and_seals_the_year(): void
    {
        $w = $this->world();
        $this->approvedLeave($w, '2027-03-01');
        $closes = app(LeaveYearCloseService::class);

        $pending = $this->submitLeave($w, '2027-03-15', '2027-03-15');
        $preview = $closes->preview($w['school'], $w['year']->id, $w['admin']);
        $this->assertSame(['LEAVE_YEAR_CLOSE_PENDING_REQUESTS'], $preview['blockers'], 'a submitted request in the year blocks the close');
        $this->assertSame('LEAVE_YEAR_CLOSE_PENDING_REQUESTS', $this->code(fn () => $this->close($w)));
        app(LeaveRequestService::class)->withdraw($w['school'], $pending->id, 'plans_changed', $w['admin']);

        $preview = $closes->preview($w['school'], $w['year']->id, $w['admin']);
        $this->assertSame([], $preview['blockers']);
        $this->assertSame([20, 10, 10, '2027-06-30'], [$preview['items'][0]['closingUnits'], $preview['items'][0]['carriedUnits'], $preview['items'][0]['lapsedUnits'], $preview['items'][0]['carriedExpiresOn']]);

        $this->close($w);
        $this->assertSame([0, 10], [$this->balance($w, $w['year']), $this->balance($w, $w['next'])]);
        $this->assertSame(['carry_forward_out' => 10, 'expiry' => 10], $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_year_id', $w['year']->id)->whereNotNull('year_close_id')->pluck('units', 'kind')->all()));

        $this->assertSame('LEAVE_YEAR_ALREADY_CLOSED', $this->code(fn () => $this->close($w)), 'a repeat never duplicates ledger rows');
        $this->assertSame(3, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->whereNotNull('year_close_id')->count()));

        // Sealed: nothing new lands in the closed year.
        $this->assertSame('LEAVE_YEAR_CLOSED', $this->code(fn () => app(LeaveLedgerService::class)->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'administrative_correction', $w['admin'])));
        $this->assertSame('LEAVE_YEAR_CLOSED', $this->code(fn () => $this->submitLeave($w, '2027-03-22', '2027-03-22')));

        // The next year has not ended, and the year after it is not open.
        $this->assertEqualsCanonicalizing(['LEAVE_YEAR_NOT_ENDED', 'LEAVE_NEXT_YEAR_NOT_OPEN'], $closes->preview($w['school'], $w['next']->id, $w['admin'])['blockers']);
    }

    #[Test]
    public function years_close_in_order_into_the_adjacent_year_even_a_transition_year(): void
    {
        $w = $this->leaveWorld();
        $this->allocate($w, 6);
        $earlier = app(LeaveYearService::class)->open($w['school'], '2025-06-01', $w['admin']);
        $this->assertEqualsCanonicalizing(['LEAVE_YEAR_CLOSE_ORDER', 'LEAVE_NEXT_YEAR_NOT_OPEN'], app(LeaveYearCloseService::class)->preview($w['school'], $w['year']->id, $w['admin'])['blockers']);

        // A start-month change makes the next year an explicit transition year.
        $this->travelTo('2027-03-10 10:00:00');
        app(LeaveYearService::class)->scheduleStartChange($w['school'], 1, '2028-01-01', $w['admin']);
        $bridge = app(LeaveYearService::class)->open($w['school'], '2027-06-01', $w['admin']);
        $this->assertTrue($bridge->is_transition);
        $this->assertSame('LEAVE_YEAR_NOT_ENDED', $this->code(fn () => $this->close($w)));

        $this->travelTo('2027-04-10 10:00:00');
        $this->assertSame('LEAVE_YEAR_CLOSE_ORDER', $this->code(fn () => $this->close($w)), 'the earlier year closes first');
        app(LeaveYearCloseService::class)->execute($w['school'], $earlier->id, $w['admin']);
        $this->close($w);
        $this->assertSame(6, (int) $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_year_id', $bridge->id)->where('kind', 'carry_forward_in')->sum('units')));
    }

    #[Test]
    public function a_cancellation_after_the_close_reconciles_under_the_original_policy_below_and_at_the_cap(): void
    {
        // Allocation 16, two approved 4-unit requests: closing 8, all carried (cap 10).
        $w = $this->world(16);
        $first = $this->approvedLeave($w, '2027-03-01');
        $second = $this->approvedLeave($w, '2027-03-08');
        $this->close($w);
        $this->assertSame([0, 8], [$this->balance($w, $w['year']), $this->balance($w, $w['next'])]);
        $closeEntries = $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->whereNotNull('year_close_id')->whereNull('year_close_reconciliation_id')->orderBy('id')->get(['id', 'kind', 'units'])->all());

        // Later the policy changes; reconciliation must still use the close's terms (cap 10).
        $assignment = $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['employment']->id)->value('id'));
        app(LeavePolicyAssignmentService::class)->end($w['school'], $assignment, '2027-04-30', $w['admin']);
        $generous = $this->leavePolicy($w['school'], $w['type'], $w['admin'], ['name' => 'Generous', 'carry_forward_cap_units' => 100]);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $generous->id, '2027-05-01', null, $w['admin']);

        // First cancellation: 8 + 4 = 12 > cap 10 -> 2 more carried, 2 lapse.
        app(LeaveRequestService::class)->cancel($w['school'], $first, 'plans_changed', $w['admin']);
        $this->assertSame([0, 10], [$this->balance($w, $w['year']), $this->balance($w, $w['next'])]);
        // Second: the cap is already saturated -> all 4 lapse.
        app(LeaveRequestService::class)->cancel($w['school'], $second, 'plans_changed', $w['admin']);
        $this->assertSame([0, 10], [$this->balance($w, $w['year']), $this->balance($w, $w['next'])]);

        $this->assertSame([[4, 2, 2], [4, 0, 4]], $this->inSchool($w['school'], fn () => DB::table('leave_year_close_reconciliations')->orderBy('created_at')->get()
            ->map(fn ($r) => [(int) $r->units, (int) $r->carried_delta, (int) $r->lapsed_delta])->all()));
        $this->assertEquals($closeEntries, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->whereNotNull('year_close_id')->whereNull('year_close_reconciliation_id')->orderBy('id')->get(['id', 'kind', 'units'])->all()), 'the original close is never rewritten');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_year_closes')->count()));

        // Replay: nothing is reversed or reconciled twice.
        $this->assertSame('LEAVE_REQUEST_NOT_APPROVED', $this->code(fn () => app(LeaveRequestService::class)->cancel($w['school'], $first, 'plans_changed', $w['admin'])));
        $this->assertSame(2, $this->inSchool($w['school'], fn () => DB::table('leave_year_close_reconciliations')->count()));
    }

    #[Test]
    public function a_cancellation_after_a_close_that_carried_nothing_lapses_the_units_and_two_closed_years_refuse(): void
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $noCarry = $this->leaveType($w['school'], $w['admin'], 'NC');
        $policy = $this->leavePolicy($w['school'], $noCarry, $w['admin'], ['name' => 'No carry', 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null]);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $policy->id, '2026-04-01', null, $w['admin']);
        app(LeaveLedgerService::class)->allocate($w['school'], $w['employment']->id, $noCarry->id, $w['year']->id, 10, $w['admin']);
        $next = app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);
        $request = $this->submitLeave($w, '2027-03-01', '2027-03-02', 'full', null, null, $noCarry);
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);
        $this->close($w);

        app(LeaveRequestService::class)->cancel($w['school'], $request->id, 'entered_in_error', $w['admin']);
        $this->assertSame([[4, 0, 4]], $this->inSchool($w['school'], fn () => DB::table('leave_year_close_reconciliations')->get()->map(fn ($r) => [(int) $r->units, (int) $r->carried_delta, (int) $r->lapsed_delta])->all()));
        $this->assertSame(0, (int) $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_year_id', $next->id)->count()), 'nothing carried');

        // Two consecutive closed years: the cancellation is refused, deterministically.
        $chained = $this->leaveWorld();
        $this->workingWeek($chained['school'], $chained['admin']);
        $this->allocate($chained, 24);
        $chainedNext = app(LeaveYearService::class)->open($chained['school'], '2027-04-01', $chained['admin']);
        $leave = $this->submitLeave($chained, '2027-03-01', '2027-03-02');
        app(LeaveRequestService::class)->approve($chained['school'], $leave->id, $chained['admin']);
        $this->close($chained);
        $this->travelTo('2028-04-10 10:00:00');
        app(LeaveYearService::class)->open($chained['school'], '2028-04-01', $chained['admin']);
        app(LeaveYearCloseService::class)->execute($chained['school'], $chainedNext->id, $chained['admin']);
        $this->assertSame('LEAVE_CANCELLATION_CLOSE_CHAIN', $this->code(fn () => app(LeaveRequestService::class)->cancel($chained['school'], $leave->id, 'plans_changed', $chained['admin'])));
    }
}
