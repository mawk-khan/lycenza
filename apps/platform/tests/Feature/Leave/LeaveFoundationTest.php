<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\CarryForwardCalculator;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearBounds;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §4): the Leave foundation -- leave year, types, policies,
 * assignments, the staff working calendar and the append-only ledger with
 * its derived balance, in integer half-day units.
 */
class LeaveFoundationTest extends TestCase
{
    use CreatesLeaveFixtures;

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 160);
        } catch (ModelNotFoundException) {
            return 'not_found';
        }
    }

    private function balance(array $w): int
    {
        $rows = app(LeaveReadService::class)->balances($w['school'], $w['employment']->id, $w['year']->id, $w['admin']);

        return (int) (collect($rows)->firstWhere('leaveTypeId', $w['type']->id)['availableUnits'] ?? 0);
    }

    #[Test]
    public function the_leave_year_is_school_configured_defaults_to_april_and_never_reinterprets_history(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $years = app(LeaveYearService::class);
        $this->assertSame(4, $years->startMonth($school), 'April default, independent of Finance');

        $this->assertSame('LEAVE_YEAR_MONTH_INVALID', $this->code(fn () => $years->setStartMonth($school, 13, $admin)));
        $years->setStartMonth($school, 6, $admin);
        $this->assertSame(6, $years->startMonth($school));

        $year = $years->open($school, '2026-05-31', $admin);
        $this->assertSame(['2025-06-01', '2026-05-31', '2025-26', 6], [$year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->label, $year->start_month]);
        $this->assertSame($year->id, $years->open($school, '2025-12-25', $admin)->id, 'opening is idempotent for a date inside the year');

        // Once a year exists the start month is fixed; the year keeps its own frozen bounds.
        $this->assertSame('LEAVE_YEAR_LOCKED', $this->code(fn () => $years->setStartMonth($school, 1, $admin)));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($school, fn () => DB::table('leave_years')->where('id', $year->id)->update(['ends_on' => '2026-06-30']))), 'append-only for the runtime role');

        $this->assertSame(['2026-01-01', '2026-12-31', '2026'], [LeaveYearBounds::containing(1, '2026-07-04')->startsOn, LeaveYearBounds::containing(1, '2026-07-04')->endsOn, LeaveYearBounds::containing(1, '2026-07-04')->label]);
        $this->assertSame(['2026-04-01', '2027-03-31', '2026-27'], [LeaveYearBounds::containing(4, '2027-03-31')->startsOn, LeaveYearBounds::containing(4, '2027-03-31')->endsOn, LeaveYearBounds::containing(4, '2027-03-31')->label]);
    }

    #[Test]
    public function leave_types_are_school_local_with_independent_rules_that_freeze_once_used(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $adminA = $this->leaveAdmin($a);
        $types = app(LeaveTypeService::class);

        foreach ([[true, true], [true, false], [false, true], [false, false]] as $i => [$paid, $tracked]) {
            $type = $types->create($a, "T{$i}", "Type {$i}", $paid, $tracked, false, $adminA);
            $this->assertSame([$paid, $tracked], [$type->is_paid, $type->tracks_balance], 'paid and balance-tracked are independent');
        }
        $this->assertSame('LEAVE_TYPE_CODE_TAKEN', $this->code(fn () => $types->create($a, 't0', 'Dup', true, true, true, $adminA)), 'codes normalize and are unique per School');
        $this->assertSame('ok', $this->code(fn () => $types->create($b, 'T0', 'Same code, other School', true, true, true, $this->leaveAdmin($b))));

        $used = $this->leaveType($a, $adminA, 'EL');
        $this->assertSame('ok', $this->code(fn () => $types->update($a, $used->id, ['allows_half_day' => false], $adminA)), 'free while unused');
        $this->leavePolicy($a, $used, $adminA, ['annual_allocation_units' => 10, 'carry_forward_cap_units' => 4]);
        $this->assertSame('LEAVE_TYPE_IN_USE', $this->code(fn () => $types->update($a, $used->id, ['is_paid' => false], $adminA)));
        $this->assertSame('ok', $this->code(fn () => $types->update($a, $used->id, ['name' => 'Earned'], $adminA)), 'the name may still change');
        $this->assertSame('inactive', $types->setStatus($a, $used->id, 'inactive', $adminA)->status);
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($a, fn () => DB::table('leave_types')->where('id', $used->id)->delete())), 'never deleted');
    }

    #[Test]
    public function policies_are_immutable_versioned_and_in_whole_or_half_days_as_the_type_allows(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $policies = app(LeavePolicyService::class);
        $whole = $this->leaveType($school, $admin, 'EL', halfDay: false);
        $untracked = $this->leaveType($school, $admin, 'UL', paid: false, tracked: false);

        $terms = ['name' => 'P', 'annual_allocation_units' => 7, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null];
        $this->assertSame('LEAVE_HALF_DAY_NOT_ALLOWED', $this->code(fn () => $policies->create($school, $whole->id, $terms, null, $admin)));
        $this->assertSame('LEAVE_POLICY_UNTRACKED_TYPE', $this->code(fn () => $policies->create($school, $untracked->id, ['annual_allocation_units' => 2] + $terms, null, $admin)));
        $this->assertSame('ok', $this->code(fn () => $policies->create($school, $untracked->id, ['annual_allocation_units' => 0] + $terms, null, $admin)), 'an untracked type has a zero-allocation policy');

        $v1 = $this->leavePolicy($school, $whole, $admin, ['annual_allocation_units' => 24, 'carry_forward_cap_units' => 10]);
        $this->assertStringContainsString('leave_policy_immutable', $this->code(fn () => $this->inSchool($school, fn () => DB::table('leave_policies')->where('id', $v1->id)->update(['annual_allocation_units' => 30]))));
        $v2 = $policies->create($school, $whole->id, ['name' => 'v2', 'annual_allocation_units' => 30, 'carry_forward_allowed' => true, 'carry_forward_cap_units' => 12, 'carry_forward_expiry_days' => null], $v1->id, $admin);
        $this->assertSame($v1->id, $v2->supersedes_policy_id);
        $this->assertSame('retired', $this->inSchool($school, fn () => $v1->fresh())->status, 'superseding retires the old version in the same transaction');
        $this->assertSame(24, $this->inSchool($school, fn () => $v1->fresh())->annual_allocation_units, 'the old terms are kept for the evidence made under them');
        $this->assertSame('LEAVE_POLICY_NOT_SUPERSEDABLE', $this->code(fn () => $policies->create($school, $whole->id, ['annual_allocation_units' => 2] + $terms, $v1->id, $admin)));
    }

    #[Test]
    public function assignments_attach_to_an_employment_never_overlap_and_only_end(): void
    {
        $w = $this->leaveWorld();
        $assignments = app(LeavePolicyAssignmentService::class);
        $assignment = $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['employment']->id)->first());

        $this->assertSame('LEAVE_ASSIGNMENT_OVERLAP', $this->code(fn () => $assignments->assign($w['school'], $w['employment']->id, $w['policy']->id, '2026-09-01', null, $w['admin'])));
        $ended = $assignments->end($w['school'], $assignment->id, '2026-12-31', $w['admin']);
        $this->assertSame('2026-12-31', $ended->effective_to->toDateString());
        $this->assertSame('LEAVE_ASSIGNMENT_ENDED', $this->code(fn () => $assignments->end($w['school'], $assignment->id, '2026-11-30', $w['admin'])));
        $this->assertSame('ok', $this->code(fn () => $assignments->assign($w['school'], $w['employment']->id, $w['policy']->id, '2027-01-01', null, $w['admin'])), 'a later period is free');
        $this->assertStringContainsString('ended assignment is immutable', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('id', $assignment->id)->update(['effective_from' => '2026-01-01', 'updated_at' => now()]))));

        // A separated employment and another School's employment are refused.
        $separated = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'separated', 'starts_on' => '2020-01-01', 'ends_on' => '2021-01-01']);
        $this->assertSame('LEAVE_EMPLOYMENT_NOT_ELIGIBLE', $this->code(fn () => $assignments->assign($w['school'], $separated->id, $w['policy']->id, '2026-04-01', null, $w['admin'])));
        $foreign = $this->currentEmployment($this->createSchool());
        $this->assertSame('not_found', $this->code(fn () => $assignments->assign($w['school'], $foreign->id, $w['policy']->id, '2026-04-01', null, $w['admin'])), 'another School\'s employment is an ordinary not-found');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $foreign->id)->count()));
    }

    #[Test]
    public function the_staff_calendar_counts_only_working_halves_and_refuses_until_configured(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $calendar = app(StaffCalendarService::class);
        $this->assertSame('LEAVE_CALENDAR_NOT_CONFIGURED', $this->code(fn () => $calendar->calculator($school, '2026-06-01', '2026-06-30')));
        $this->assertSame('LEAVE_CALENDAR_PATTERN_INVALID', $this->code(fn () => $calendar->setWeeklyPattern($school, [1 => 'full'], $admin)));

        $calendar->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'first_half', 7 => 'off'], $admin);
        $calendar->addHoliday($school, '2026-06-03', DayPortion::Full, 'Founders Day', $admin);
        $calendar->addHoliday($school, '2026-06-04', DayPortion::SecondHalf, 'Half holiday', $admin);
        $this->assertSame('LEAVE_HOLIDAY_EXISTS', $this->code(fn () => $calendar->addHoliday($school, '2026-06-03', DayPortion::Full, 'Again', $admin)));

        $c = $calendar->calculator($school, '2026-06-01', '2026-06-30');
        // 2026-06-01 Monday .. 2026-06-07 Sunday
        $this->assertSame(2, $c->units('2026-06-01', DayPortion::Full), 'a full working day is 2 units');
        $this->assertSame(1, $c->units('2026-06-01', DayPortion::SecondHalf));
        $this->assertSame(0, $c->units('2026-06-03', DayPortion::Full), 'a holiday consumes nothing');
        $this->assertSame(1, $c->units('2026-06-04', DayPortion::Full), 'a half holiday leaves its working half');
        $this->assertSame(0, $c->units('2026-06-04', DayPortion::SecondHalf));
        $this->assertSame(1, $c->units('2026-06-06', DayPortion::Full), 'a first-half-only Saturday counts one unit');
        $this->assertSame(0, $c->units('2026-06-06', DayPortion::SecondHalf));
        $this->assertSame(0, $c->units('2026-06-07', DayPortion::Full), 'a weekly off consumes nothing');
        $this->assertSame([2, 1, 1], [DayPortion::Full->units(), DayPortion::FirstHalf->units(), DayPortion::SecondHalf->units()]);
    }

    #[Test]
    public function allocations_are_explicit_exact_and_once_per_year_with_no_proration(): void
    {
        $w = $this->leaveWorld();
        $ledger = app(LeaveLedgerService::class);

        // A mid-year joiner: the administrator grants exactly the units chosen; nothing is prorated.
        $entry = $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 9, $w['admin']);
        $this->assertSame(['allocation', 9, null], [$entry->kind, $entry->units, $entry->direction]);
        $this->assertSame(9, $this->balance($w));
        $this->assertSame('LEAVE_ALREADY_ALLOCATED', $this->code(fn () => $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 9, $w['admin'])));
        $this->assertSame('LEAVE_UNITS_INVALID', $this->code(fn () => $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 0, $w['admin'])));

        // The annual run grants the policy's units to everyone else and skips the explicit grant.
        $other = $this->currentEmployment($w['school']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $other->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->assertCount(1, $ledger->runCandidates($w['school'], $w['type']->id, $w['year']->id, $w['admin']));
        $run = $ledger->executeRun($w['school'], $w['type']->id, $w['year']->id, $w['admin']);
        $this->assertSame(1, $run->allocated_count);
        $this->assertSame(24, (int) $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $other->id)->value('units')));
        $this->assertSame(9, $this->balance($w), 'the explicit grant is never duplicated or replaced');
        $this->assertSame('LEAVE_RUN_ALREADY_EXECUTED', $this->code(fn () => $ledger->executeRun($w['school'], $w['type']->id, $w['year']->id, $w['admin'])));

        // No policy, no allocation.
        $bare = $this->currentEmployment($w['school']);
        $this->assertSame('LEAVE_NO_POLICY_ASSIGNMENT', $this->code(fn () => $ledger->allocate($w['school'], $bare->id, $w['type']->id, $w['year']->id, 4, $w['admin'])));
    }

    #[Test]
    public function adjustments_are_append_only_corrections_and_no_balance_ever_goes_negative(): void
    {
        $w = $this->leaveWorld();
        $ledger = app(LeaveLedgerService::class);
        $this->assertSame('LEAVE_NOT_ALLOCATED', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'entitlement_change', $w['admin'])));

        $allocation = $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 10, $w['admin']);
        $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 3, 'entitlement_change', $w['admin']);
        $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'debit', 5, 'allocation_correction', $w['admin']);
        $this->assertSame(8, $this->balance($w), '10 + 3 - 5');

        $this->assertSame('LEAVE_BALANCE_INSUFFICIENT', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'debit', 9, 'allocation_correction', $w['admin'])));
        $this->assertSame('LEAVE_ADJUSTMENT_REASON_INVALID', $this->code(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 1, 'because', $w['admin'])));
        $this->assertSame(8, $this->balance($w));

        // The database holds the invariant itself, for any writer.
        $raw = fn (array $row) => $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->insert($row + [
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id,
            'tracks_balance' => true, 'leave_year_id' => $w['year']->id, 'actor_user_id' => $w['admin']->id, 'created_at' => now(),
        ]));
        $this->assertStringContainsString('leave_balance_negative', $this->code(fn () => $raw(['kind' => 'expiry', 'units' => 9])));
        $this->assertStringContainsString('leave_reversal_invalid', $this->code(fn () => $raw(['kind' => 'reversal', 'units' => 10, 'reverses_entry_id' => $allocation->id])), 'a reversal undoes a consumption only');
        $this->assertStringContainsString('leave_ledger_entries_shape_check', $this->code(fn () => $raw(['kind' => 'allocation', 'units' => -2])), 'units are positive; the kind carries the sign');
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('id', $allocation->id)->update(['units' => 99]))), 'append-only');
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('id', $allocation->id)->delete())));

        // An untracked type can never hold ledger entries.
        $untracked = $this->leaveType($w['school'], $w['admin'], 'UL', paid: false, tracked: false);
        $this->assertStringContainsString('leave_ledger_entries_type_fk', $this->code(fn () => $raw(['kind' => 'allocation', 'units' => 2, 'leave_type_id' => $untracked->id])));
        $this->assertSame('LEAVE_TYPE_UNTRACKED', $this->code(fn () => $ledger->allocate($w['school'], $w['employment']->id, $untracked->id, $w['year']->id, 2, $w['admin'])));

        // The derived balance reconciles to its entries.
        $entries = app(LeaveReadService::class)->ledger($w['school'], $w['employment']->id, $w['year']->id, $w['admin']);
        $sum = array_sum(array_map(fn ($e) => in_array($e['kind'], ['allocation', 'reversal', 'carry_forward_in'], true) || $e['direction'] === 'credit' ? $e['units'] : -$e['units'], $entries));
        $this->assertSame($this->balance($w), $sum);
    }

    #[Test]
    public function carry_forward_is_capped_deterministic_integer_arithmetic(): void
    {
        $w = $this->leaveWorld();
        $calculator = new CarryForwardCalculator;

        $this->assertSame(['carried' => 10, 'lapsed' => 5, 'carried_expire_on' => '2027-06-30'], $calculator->plan($w['policy'], 15, '2027-04-01'), 'cap 10, expiry 90 days after the new year starts');
        $this->assertSame(['carried' => 4, 'lapsed' => 0, 'carried_expire_on' => '2027-06-30'], $calculator->plan($w['policy'], 4, '2027-04-01'));
        $noCarry = $this->leavePolicy($w['school'], $this->leaveType($w['school'], $w['admin'], 'SL'), $w['admin'], ['carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null]);
        $this->assertSame(['carried' => 0, 'lapsed' => 7, 'carried_expire_on' => null], $calculator->plan($noCarry, 7, '2027-04-01'));
    }

    #[Test]
    public function one_school_never_reads_or_writes_another_schools_leave(): void
    {
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();
        $ledger = app(LeaveLedgerService::class);
        $ledger->allocate($a['school'], $a['employment']->id, $a['type']->id, $a['year']->id, 6, $a['admin']);

        // School B's administrator, in B's context, cannot see or touch A's rows.
        $this->assertSame([], app(LeaveReadService::class)->ledger($b['school'], $a['employment']->id, $a['year']->id, $b['admin']));
        $this->assertNotSame('ok', $this->code(fn () => $ledger->allocate($b['school'], $a['employment']->id, $a['type']->id, $a['year']->id, 2, $b['admin'])));
        $this->assertNotSame('ok', $this->code(fn () => $ledger->allocate($b['school'], $b['employment']->id, $a['type']->id, $b['year']->id, 2, $b['admin'])), 'another School\'s leave type');
        $this->assertSame(0, $this->inSchool($b['school'], fn () => DB::table('leave_ledger_entries')->count()));
        $this->assertSame(1, $this->inSchool($a['school'], fn () => DB::table('leave_ledger_entries')->count()));
        $this->assertNotNull(School::query()->find($a['school']->id));
    }
}
