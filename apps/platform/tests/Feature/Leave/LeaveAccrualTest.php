<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\Accrual\LeaveStatementReader;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Infrastructure\LeaveAccrualRun;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.10 (ADR 0065 §29): monthly accrual on the ONE ledger -- the version's
 * method (exclusive with annual allocation), whole-month eligibility from
 * HR.1A dated facts, no proration, disjoint outcomes that reconcile, retry
 * and re-run without double credit, provenance, closed-year and funding
 * backstops, and the balance identity.
 *
 * The leave year is 2026-04-01..2027-03-31; today is Monday 2026-10-05.
 * Every number is a synthetic test input.
 */
class LeaveAccrualTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private User $admin;

    private LeaveType $type;

    private LeaveYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $this->type = $this->leaveType($this->school, $this->admin);
        $this->year = $this->leaveYear($this->school, $this->admin);
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
        } catch (AuthorizationException) {
            return 'forbidden';
        }
    }

    /** @param  array<string, mixed>  $terms */
    private function monthly(int $units = 3, string $timing = 'period_start', array $terms = [], ?LeavePolicy $supersedes = null, array $rules = []): LeavePolicy
    {
        return app(LeavePolicyService::class)->create($this->school, $this->type->id, $terms + [
            'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => $units, 'accrual_timing' => $timing,
        ], $supersedes?->id, $this->admin, $rules);
    }

    private function employment(string $startsOn = '2024-01-01', ?string $endsOn = null, string $status = 'active', ?string $staffType = 'teaching'): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'staff_type' => $staffType]);
    }

    private function assign(EmploymentRecord $e, LeavePolicy $p, string $from = '2026-04-01', ?string $to = null): void
    {
        app(LeavePolicyAssignmentService::class)->assign($this->school, $e->id, $p->id, $from, $to, $this->admin);
    }

    private function accrue(string $month, int $maxBatches = 1000): LeaveAccrualRun
    {
        return app(LeaveAccrualService::class)->execute($this->school, $this->type->id, $this->year->id, $month, $this->admin, $maxBatches);
    }

    /** @return array<string, array<string, mixed>> */
    private function preview(string $month): array
    {
        $preview = app(LeaveAccrualService::class)->preview($this->school, $this->type->id, $this->year->id, $month, $this->admin, perPage: 200);

        return collect($preview['rows'])->keyBy('employmentRecordId')->all() + ['_counts' => $preview['counts']];
    }

    /** A raw PROCESSING run of one month (the runtime role may write one), so a raw accrual reaches the check under test. */
    private function processingRun(string $month): string
    {
        $id = (string) Str::uuid();
        $this->inSchool($this->school, fn () => DB::table('leave_accrual_runs')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'leave_type_id' => $this->type->id, 'leave_year_id' => $this->year->id,
            'period_start' => $month, 'status' => 'processing', 'started_by_user_id' => $this->admin->id, 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    /** @return list<LeaveLedgerEntry> */
    private function accruals(EmploymentRecord $e): array
    {
        return $this->inSchool($this->school, fn () => LeaveLedgerEntry::query()->where('employment_record_id', $e->id)->where('kind', 'accrual')->orderBy('accrual_period')->get()->all());
    }

    private function balance(EmploymentRecord $e): int
    {
        return (int) $this->inSchool($this->school, fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $e->id)
            ->where('leave_type_id', $this->type->id)->where('leave_year_id', $this->year->id)
            ->selectRaw('coalesce(sum('.LeaveLedgerEntry::signedUnitsSql().'), 0) as b')->value('b'));
    }

    #[Test]
    public function a_version_is_funded_annually_or_monthly_never_both(): void
    {
        $base = ['name' => 'X', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null];
        $create = fn (array $t, ?LeaveType $type = null) => fn () => app(LeavePolicyService::class)->create($this->school, ($type ?? $this->type)->id, $t + $base, null, $this->admin);

        $this->assertSame('LEAVE_ENTITLEMENT_METHOD_INVALID', $this->code($create(['entitlement_method' => 'monthly', 'annual_allocation_units' => 24, 'monthly_accrual_units' => 2, 'accrual_timing' => 'period_start'])));
        $this->assertSame('LEAVE_ENTITLEMENT_METHOD_INVALID', $this->code($create(['entitlement_method' => 'monthly', 'monthly_accrual_units' => 63, 'accrual_timing' => 'period_start'])));
        $this->assertSame('LEAVE_ENTITLEMENT_METHOD_INVALID', $this->code($create(['entitlement_method' => 'monthly', 'monthly_accrual_units' => 2, 'accrual_timing' => null])));
        $this->assertSame('LEAVE_ENTITLEMENT_METHOD_INVALID', $this->code($create(['entitlement_method' => 'annual', 'monthly_accrual_units' => 2])));
        $this->assertSame('LEAVE_ENTITLEMENT_METHOD_INVALID', $this->code($create(['entitlement_method' => 'weekly'])));
        $whole = $this->leaveType($this->school, $this->admin, 'WL', halfDay: false);
        $this->assertSame('LEAVE_HALF_DAY_NOT_ALLOWED', $this->code($create(['entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_end'], $whole)), 'whole-day types accrue even units');
        $unpaid = $this->leaveType($this->school, $this->admin, 'UL', paid: false, tracked: false);
        $this->assertSame('LEAVE_POLICY_UNTRACKED_TYPE', $this->code($create(['entitlement_method' => 'monthly', 'monthly_accrual_units' => 2, 'accrual_timing' => 'period_end'], $unpaid)));
        $this->assertSame('ok', $this->code($create(['entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_end'])));

        // The database refuses the mixed shape too, and the method is frozen with the version.
        $this->assertStringContainsString('leave_policies_entitlement_check', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_policies')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'leave_type_id' => $this->type->id, 'name' => 'Raw', 'annual_allocation_units' => 10,
            'carry_forward_allowed' => false, 'status' => 'active', 'created_by_user_id' => $this->admin->id, 'entitlement_method' => 'monthly',
            'monthly_accrual_units' => 2, 'accrual_timing' => 'period_start', 'created_at' => now(), 'updated_at' => now(),
        ]))));
        $policy = $this->monthly();
        $this->assertStringContainsString('leave_policy_immutable', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_policies')->where('id', $policy->id)->update(['monthly_accrual_units' => 4]))));
    }

    #[Test]
    public function a_month_credits_only_whole_months_and_every_outcome_is_disjoint_and_reconciles(): void
    {
        $policy = $this->monthly(3);

        $full = $this->employment();
        $this->assign($full, $policy);
        $joiner = $this->employment('2026-09-15');
        $this->assign($joiner, $policy, '2026-09-15');
        $leaver = $this->employment('2024-01-01', '2026-09-20', 'notice_period');
        $this->assign($leaver, $policy, '2026-04-01', '2026-09-20');
        $midAssigned = $this->employment();
        $this->assign($midAssigned, $policy, '2026-09-10');
        $unassigned = $this->employment();
        $assignedLater = $this->employment();
        $this->assign($assignedLater, $this->monthly(4, terms: ['name' => 'Other'], rules: []), '2026-10-01');

        $preview = $this->preview('2026-09-01');
        $this->assertSame(['credited', 3], [$preview[$full->id]['outcome'], $preview[$full->id]['units']]);
        $this->assertSame('partial_period', $preview[$joiner->id]['outcome'], 'a joiner in the month is never prorated');
        $this->assertSame('partial_period', $preview[$leaver->id]['outcome'], 'a leaver in the month is never prorated');
        $this->assertSame('assignment_not_whole_period', $preview[$midAssigned->id]['outcome']);
        $this->assertSame('no_policy', $preview[$unassigned->id]['outcome']);
        $this->assertSame('no_policy', $preview[$assignedLater->id]['outcome'], 'an assignment starting after the month');
        $this->assertSame([], $this->accruals($full), 'a preview writes nothing');

        $run = $this->accrue('2026-09-01');
        $this->assertSame('completed', $run->status);
        $show = app(LeaveAccrualService::class)->show($this->school, $run->id, $this->admin);
        $this->assertSame($show['examined'], array_sum($show['counts']), 'examined = the sum of the disjoint outcomes');
        $this->assertSame(1, $show['counts']['credited']);
        $this->assertSame(2, $show['counts']['partial_period']);
        $entry = $this->accruals($full)[0];
        $this->assertSame(['2026-09-01', 3, $run->id], [$entry->accrual_period->toDateString(), $entry->units, $entry->accrual_run_id]);
        $this->assertNotNull($entry->leave_policy_assignment_id, 'traceable to the assignment and so the version');

        // A re-run of the month credits nothing twice and still reconciles.
        $again = $this->accrue('2026-09-01');
        $againShow = app(LeaveAccrualService::class)->show($this->school, $again->id, $this->admin);
        $this->assertSame(1, $againShow['counts']['already_credited']);
        $this->assertSame(0, $againShow['counts']['credited']);
        $this->assertCount(1, $this->accruals($full));
        $this->assertSame(3, $this->balance($full));
    }

    #[Test]
    public function timing_decides_when_a_month_is_due_and_a_future_month_never_starts(): void
    {
        $arrears = $this->monthly(2, 'period_end');
        $e = $this->employment();
        $this->assign($e, $arrears);

        $this->assertSame('not_due', $this->preview('2026-10-01')[$e->id]['outcome'], 'October is due in arrears on 2026-11-01');
        $this->assertSame('credited', $this->preview('2026-09-01')[$e->id]['outcome']);
        $this->assertSame('LEAVE_ACCRUAL_PERIOD_NOT_DUE', $this->code(fn () => $this->accrue('2026-11-01')));
        $this->assertSame('LEAVE_ACCRUAL_PERIOD_INVALID', $this->code(fn () => $this->accrue('2026-09-15')));
        $this->assertSame('LEAVE_ACCRUAL_PERIOD_INVALID', $this->code(fn () => $this->accrue('2026-03-01')), 'outside the leave year');
    }

    #[Test]
    public function funding_is_exclusive_between_annual_allocation_and_accrual(): void
    {
        $monthly = $this->monthly(2);
        $e = $this->employment();
        $this->assign($e, $monthly);
        $this->assertSame('LEAVE_POLICY_ACCRUES_MONTHLY', $this->code(fn () => app(LeaveLedgerService::class)->allocate($this->school, $e->id, $this->type->id, $this->year->id, 10, $this->admin)));

        // An account already funded by an annual allocation is never also accrued (outcome + database).
        $annualPolicy = $this->leavePolicy($this->school, $this->type, $this->admin, ['name' => 'Annual']);
        $f = $this->employment();
        $this->assign($f, $annualPolicy, '2026-04-01', '2026-06-30');
        $this->assign($f, $monthly, '2026-07-01');
        app(LeaveLedgerService::class)->executeRun($this->school, $this->type->id, $this->year->id, $this->admin);
        $this->assertSame('annual_allocation_exists', $this->preview('2026-08-01')[$f->id]['outcome']);
        $this->accrue('2026-08-01');
        $this->assertSame([], $this->accruals($f));

        $assignment = $this->inSchool($this->school, fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $f->id)->where('leave_policy_id', $monthly->id)->value('id'));
        $runId = $this->processingRun('2026-08-01');
        $this->assertStringContainsString('leave_funding_conflict', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_ledger_entries')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'employment_record_id' => $f->id, 'leave_type_id' => $this->type->id, 'tracks_balance' => true,
            'leave_year_id' => $this->year->id, 'leave_policy_assignment_id' => $assignment, 'kind' => 'accrual', 'units' => 2, 'accrual_period' => '2026-08-01',
            'accrual_run_id' => $runId, 'actor_user_id' => $this->admin->id, 'created_at' => now(),
        ]))));
    }

    #[Test]
    public function the_database_checks_every_accrual_against_its_assignment_and_month(): void
    {
        $monthly = $this->monthly(3);
        $e = $this->employment();
        $this->assign($e, $monthly, '2026-04-01');
        $run = $this->accrue('2026-04-01');
        $may = $this->processingRun('2026-05-01');
        $assignment = $this->inSchool($this->school, fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $e->id)->value('id'));
        $raw = fn (array $over) => $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_ledger_entries')->insert($over + [
            'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'employment_record_id' => $e->id, 'leave_type_id' => $this->type->id, 'tracks_balance' => true,
            'leave_year_id' => $this->year->id, 'leave_policy_assignment_id' => $assignment, 'kind' => 'accrual', 'units' => 3, 'accrual_period' => '2026-05-01',
            'accrual_run_id' => $may, 'actor_user_id' => $this->admin->id, 'created_at' => now(),
        ])));

        $this->assertStringContainsString('leave_accrual_invalid', $raw(['units' => 5]), 'never more than the version grants');
        $this->assertStringContainsString('leave_accrual_invalid', $raw(['accrual_period' => '2027-04-01']), 'never outside the year');
        $this->assertStringContainsString('leave_accrual_invalid', $raw(['accrual_run_id' => $run->id]), 'never under a completed run, nor another month\'s');
        $this->assertStringContainsString('leave_accrual_runs_shape_check', $this->code(fn () => $this->processingRun('2026-06-02')), 'a run month starts on day 1');
        $this->assertSame('ok', $raw([]), 'the whole-month monthly assignment, its units, a processing run of the month');
        $this->assertStringContainsString('leave_ledger_entries_one_accrual', $raw([]), 'never twice for a month');

        // Run evidence is immutable and a completed run is final.
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_accrual_run_items')->where('accrual_run_id', $run->id)->update(['outcome' => 'no_policy']))));
        $this->assertStringContainsString('leave_accrual_run_immutable', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_accrual_runs')->where('id', $run->id)->update(['status' => 'processing', 'completed_at' => null]))));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_accrual_runs')->where('id', $run->id)->delete())));
    }

    #[Test]
    public function a_closed_year_receives_no_accrual(): void
    {
        $this->travelTo('2027-04-10 06:00:00');
        $monthly = $this->monthly(2);
        $e = $this->employment();
        $this->assign($e, $monthly);
        $this->leaveYear($this->school, $this->admin, '2027-05-01');
        app(LeaveYearCloseService::class)->execute($this->school, $this->year->id, $this->admin);

        $this->assertSame('LEAVE_YEAR_CLOSED', $this->code(fn () => $this->accrue('2027-03-01')));
    }

    #[Test]
    public function dated_facts_decide_rehire_transfer_supersession_and_drift(): void
    {
        $dept = $this->createDepartment($this->school);
        $other = $this->createDepartment($this->school);
        $v1 = $this->monthly(2, rules: [new LeaveEligibilityCriteria(null, null, $dept->id)]);

        // Transfer: the same employment and assignment keep accruing; drift is flagged, never acted on.
        $mover = $this->employment();
        $primary = $this->createEmployeeAssignment($mover, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true, 'department_id' => $dept->id]);
        $this->assign($mover, $v1);
        $position = $this->inSchool($this->school, fn () => Position::query()->findOrFail($primary->position_id));
        app(PlacementChangeService::class)->change($this->inSchool($this->school, fn () => EmployeeAssignment::query()->findOrFail($primary->id)), PlacementChangeKind::DEPARTMENT_CHANGE,
            '2026-07-01', PlacementChangeReason::RESTRUCTURING, $position, $this->fullHrActor($this->school), department: $other);

        // Supersession: the assignment keeps its version; a later version never re-prices a month.
        $v2 = $this->monthly(4, terms: ['name' => 'Monthly v2'], supersedes: $v1, rules: [new LeaveEligibilityCriteria(null, null, $dept->id)]);

        // Rehire: a new employment is a new account.
        $employee = $this->createEmployee($this->school);
        $first = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->assign($first, $v2, '2026-04-01', '2026-05-31');
        $this->inSchool($this->school, fn () => DB::table('employment_records')->where('id', $first->id)->update(['status' => 'separated', 'ends_on' => '2026-05-31']));
        $second = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2026-08-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->assign($second, $v2, '2026-08-01');

        foreach (['2026-05-01', '2026-06-01', '2026-08-01'] as $month) {
            $this->accrue($month);
        }
        $this->assertSame([2, 2, 2], array_map(fn ($e) => $e->units, $this->accruals($mover)), 'the assigned version (v1) priced every month, before and after the transfer and the supersession');
        $this->assertSame(['2026-05-01'], array_map(fn ($e) => $e->accrual_period->toDateString(), $this->accruals($first)), 'an ended employment accrues the months it spanned, never after');
        $this->assertSame(['2026-08-01'], array_map(fn ($e) => $e->accrual_period->toDateString(), $this->accruals($second)));
        $this->assertSame(4, $this->balance($second), 'the rehire starts its own balance (v2 units, its own assignment)');

        $preview = $this->preview('2026-09-01');
        $this->assertTrue($preview[$mover->id]['eligibilityDrift'], 'the mover now matches no rule of the assigned version: flagged');
        $this->assertSame('credited', $preview[$mover->id]['outcome'], 'drift never stops or re-points the accrual');
    }

    #[Test]
    public function the_operators_own_employment_is_credited_by_the_policy_driven_run(): void
    {
        $monthly = $this->monthly(2);
        $own = $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => $this->admin->id]), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        // Another administrator assigns it (G-4: nobody assigns their own policy).
        app(LeavePolicyAssignmentService::class)->assign($this->school, $own->id, $monthly->id, '2026-04-01', null, $this->leaveAdmin($this->school));
        $this->accrue('2026-04-01');

        $this->assertCount(1, $this->accruals($own), 'policy-driven: the operator chooses no amount (the annual run precedent, ORG.8D)');
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => app(LeaveLedgerService::class)->recordOpening($this->school, $own->id, $this->type->id, $this->year->id, 4, $this->admin)));
    }

    #[Test]
    public function accrued_leave_is_consumed_cancelled_and_stated_by_the_existing_rules(): void
    {
        $this->workingWeek($this->school, $this->admin);
        $monthly = $this->monthly(4);
        $e = $this->employment();
        $this->assign($e, $monthly);
        $this->accrue('2026-04-01');
        $this->accrue('2026-05-01');
        $w = ['school' => $this->school, 'type' => $this->type, 'employment' => $e, 'admin' => $this->admin];
        $request = $this->submitLeave($w, '2026-10-12', '2026-10-13');
        $requests = app(LeaveRequestService::class);
        $requests->approve($this->school, $request->id, $this->admin);
        $this->assertSame(4, $this->balance($e), '8 accrued - 4 consumed (approval-controlled, never reserved at submission)');

        $tooMuch = $this->submitLeave($w, '2026-10-19', '2026-10-21');
        $this->assertSame('LEAVE_BALANCE_INSUFFICIENT', $this->code(fn () => $requests->approve($this->school, $tooMuch->id, $this->admin)));
        $requests->cancel($this->school, $request->id, 'plans_changed', $this->admin);
        $this->assertSame(8, $this->balance($e));

        $statement = app(LeaveStatementReader::class)->statement($this->school, $e->id, $this->type->id, $this->year->id, $this->admin);
        $this->assertTrue($statement['identityHolds']);
        $this->assertSame([0, 12, 4, 8], [$statement['opening'], $statement['credits'], $statement['debits'], $statement['closing']]);
        $this->assertSame([8, -4, 4], [$statement['byKind']['accrual'], $statement['byKind']['consumption'], $statement['byKind']['reversal']]);
    }

    #[Test]
    public function each_operation_needs_its_capability_and_schools_never_meet(): void
    {
        $this->monthly(2);
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $service = app(LeaveAccrualService::class);
        $this->assertSame('forbidden', $this->code(fn () => $service->preview($this->school, $this->type->id, $this->year->id, '2026-09-01', $viewer)));
        $this->assertSame('forbidden', $this->code(fn () => $service->execute($this->school, $this->type->id, $this->year->id, '2026-09-01', $viewer)));
        $run = $this->accrue('2026-09-01');
        $this->assertSame('ok', $this->code(fn () => $service->show($this->school, $run->id, $viewer)));
        $this->assertSame('ok', $this->code(fn () => app(LeaveStatementReader::class)->grid($this->school, $this->type->id, $this->year->id, $viewer)));

        $other = $this->createSchool();
        $otherAdmin = $this->leaveAdmin($other);
        $this->assertSame('not_found', $this->code(fn () => $service->show($other, $run->id, $otherAdmin)), 'a foreign run is unknown');
        $this->assertSame('not_found', $this->code(fn () => $service->execute($other, $this->type->id, $this->year->id, '2026-09-01', $otherAdmin)));
        $this->assertSame('forbidden', $this->code(fn () => $service->show($other, $run->id, $this->admin)));
        $audit = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'leave.accrual_run.completed')->firstOrFail());
        $keys = array_keys($audit->metadata);
        sort($keys);
        $this->assertSame(['batchCount', 'leaveTypeId', 'leaveYearId', 'outcomes', 'periodStart'], $keys, 'ids, a date and counts only');
    }

    #[Test]
    public function an_assignment_is_never_shortened_past_a_month_it_funded(): void
    {
        $e = $this->employment();
        $this->assign($e, $this->monthly(2));
        $this->accrue('2026-05-01');
        $assignment = $this->inSchool($this->school, fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $e->id)->value('id'));
        $service = app(LeavePolicyAssignmentService::class);

        $this->assertSame('LEAVE_ASSIGNMENT_ACCRUED', $this->code(fn () => $service->end($this->school, $assignment, '2026-05-15', $this->admin)));
        $this->assertStringContainsString('leave_assignment_accrued', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_policy_assignments')
            ->where('id', $assignment)->update(['effective_to' => '2026-04-30', 'ended_at' => now(), 'ended_by_user_id' => $this->admin->id]))), 'raw SQL too');
        $this->assertSame('ok', $this->code(fn () => $service->end($this->school, $assignment, '2026-05-31', $this->admin)), 'after the last credited month');
    }

    #[Test]
    public function rollback_refuses_while_accrual_evidence_exists(): void
    {
        $e = $this->employment();
        $this->assign($e, $this->monthly(2));
        $this->accrue('2026-04-01');
        $migration = require database_path('migrations/2027_01_29_090000_create_leave_accrual_and_opening.php');
        try {
            $this->inSchool($this->school, fn () => $migration->down());
            $this->fail('down() must refuse while HR.10 evidence exists');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('Refusing to roll back HR.10', $ex->getMessage());
        }
        $this->assertCount(1, $this->accruals($e), 'nothing was dropped');
    }

    #[Test]
    public function the_grid_shows_posted_months_and_never_a_fabricated_zero(): void
    {
        $monthly = $this->monthly(3);
        $e = $this->employment();
        $this->assign($e, $monthly);
        $idle = $this->employment();
        $this->accrue('2026-04-01');

        $grid = app(LeaveStatementReader::class)->grid($this->school, $this->type->id, $this->year->id, $this->admin, perPage: 200);
        $this->assertCount(12, $grid['months']);
        $rows = collect($grid['rows'])->keyBy('employmentRecordId');
        $this->assertSame(3, $rows[$e->id]['months'][0]);
        $this->assertNull($rows[$e->id]['months'][1], 'May is not posted: null, not 0');
        $this->assertNull($rows[$idle->id]['balanceUnits'], 'no ledger entry: unknown, not zero');
    }
}
