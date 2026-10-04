<?php

namespace Tests\Feature\Payroll\Hrx;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Payroll\Application\PayrollHrxInputReadService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.8-§26.13): Payroll snapshots the HRX evidence beside
 * each regular-run result -- replaced on recalculation, frozen from approval,
 * deleted only with its result -- and a later HRX change surfaces as a
 * fingerprint difference, never as a rewrite. HRX-L4 is open: the evidence
 * never changes an amount.
 */
class PayrollHrxSnapshotTest extends TestCase
{
    use CreatesPayrollHrxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function snapshot(array $w, string $runId, $employment = null): object
    {
        return $this->inSchool($w['school'], fn () => DB::table('payroll_run_hrx_inputs')->where('payroll_run_id', $runId)->where('employment_record_id', ($employment ?? $w['employment'])->id)->first());
    }

    private function preparer(array $w)
    {
        return $this->createUserWithCapabilities($w['school'], ['payroll.runs.prepare']);
    }

    #[Test]
    public function calculation_captures_one_versioned_snapshot_per_result_with_exact_units_and_recalculation_replaces_it(): void
    {
        $w = $this->hrxWorld();
        $this->approvedLeaveOf($w, '2026-09-08', '2026-09-08', $w['unpaid'], 'first_half');
        $this->recordAttendance($w, '2026-09-09', 'absent', 'absent');
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'calculated');

        $s = $this->snapshot($w, $runId);
        $this->assertSame(['hrx_payroll_input.v1', 'input_incomplete', 1, 2, 48], [$s->contract_version, $s->completeness, $s->approved_unpaid_leave_half_units, $s->recorded_absence_half_units, $s->required_working_half_units]);
        $this->assertSame($this->evidence($w)->fingerprint, $s->fingerprint);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'payroll.hrx_input.captured')->count()));

        // Recalculating a calculated run refreshes the evidence: one snapshot, the new fingerprint.
        $this->recordAttendance($w, '2026-09-10', 'absent', null);
        $this->inSchool($w['school'], fn () => app(PayrollRunAdministrationService::class)->calculate(PayrollRun::query()->findOrFail($runId), $this->preparer($w)));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('payroll_run_hrx_inputs')->where('payroll_run_id', $runId)->count()), 'never two active snapshots');
        $this->assertSame([3, $this->evidence($w)->fingerprint], [(int) $this->snapshot($w, $runId)->recorded_absence_half_units, $this->snapshot($w, $runId)->fingerprint]);
    }

    #[Test]
    public function hrx_evidence_never_changes_an_amount_while_hrx_l4_is_open(): void
    {
        $w = $this->hrxWorld();
        $this->approvedLeaveOf($w, '2026-09-07', '2026-09-11', $w['unpaid']);           // a whole week of unpaid leave
        foreach (['2026-09-14', '2026-09-15', '2026-09-16'] as $date) {
            $this->recordAttendance($w, $date, 'absent', 'absent');                       // three days absent
        }
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'posted');

        $result = $this->inSchool($w['school'], fn () => DB::table('payroll_run_results')->where('payroll_run_id', $runId)->first());
        $this->assertSame(['40000.00', '0.00', '40000.00'], [$result->gross_amount, $result->total_deductions, $result->net_amount], 'unpaid leave and absence did not reduce pay');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('payroll_run_result_lines')->where('payroll_run_result_id', $result->id)->where('amount', '<', '0')->count()));
        $snapshot = $this->snapshot($w, $runId);
        $this->assertSame([10, 6], [(int) $snapshot->approved_unpaid_leave_half_units, (int) $snapshot->recorded_absence_half_units], 'the evidence is captured, exactly, in half-day units -- and nothing else');
    }

    #[Test]
    public function a_posted_snapshot_is_frozen_and_later_hrx_changes_surface_as_a_difference_never_a_rewrite(): void
    {
        $w = $this->hrxWorld();
        $cancelLater = $this->approvedLeaveOf($w, '2026-09-21', '2026-09-21', $w['unpaid']);
        $record = $this->recordAttendance($w, '2026-09-22', 'absent', 'absent');
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'posted');
        $captured = (array) $this->snapshot($w, $runId);
        $reads = app(PayrollHrxInputReadService::class);
        $preparer = $this->preparer($w);
        $this->assertSame('none', $reads->forRun($w['school'], $runId, $preparer)['differenceState']);

        app(LeaveRequestService::class)->cancel($w['school'], $cancelLater->id, 'plans_changed', $w['admin']);            // later cancellation
        $this->approvedLeaveOf($w, '2026-09-23', '2026-09-23', $w['unpaid']);                                              // later approval
        $this->correctAttendance($w, $record, 'present', 'present', 'late_information');                                   // later correction

        $this->assertSame($captured, (array) $this->snapshot($w, $runId), 'the posted snapshot is untouched');
        $view = $reads->forRun($w['school'], $runId, $preparer);
        $this->assertSame(['source_changed_after_approval', true, true], [$view['differenceState'], $view['frozen'], $view['inputs'][0]['sourceChanged']]);
        $this->assertSame($captured['fingerprint'], $view['inputs'][0]['fingerprint']);
        $this->assertNotSame($captured['fingerprint'], $view['inputs'][0]['current']['fingerprint']);
        $this->assertSame([2, 2], [$view['inputs'][0]['units']['approvedUnpaidLeaveHalfUnits'], $view['inputs'][0]['current']['units']['approvedUnpaidLeaveHalfUnits']]);
        $this->assertSame([2, 0], [$view['inputs'][0]['units']['recordedAbsenceHalfUnits'], $view['inputs'][0]['current']['units']['recordedAbsenceHalfUnits']]);
        $this->assertSame('pending_hrx_l4', $view['payrollPolicy']);

        $this->assertSame(['payrollRunId' => $runId, 'checked' => 1, 'changed' => 1], $reads->checkDifferences($w['school'], $runId, $preparer));
        $event = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'payroll.hrx_input.difference_detected')->first());
        $this->assertSame($captured['fingerprint'], json_decode($event->metadata, true)['capturedFingerprint']);
        $this->assertSame('posted', $this->inSchool($w['school'], fn () => DB::table('payroll_runs')->where('id', $runId)->value('status')), 'nothing was reposted');
    }

    #[Test]
    public function the_runtime_role_cannot_change_insert_or_delete_a_frozen_snapshot(): void
    {
        $w = $this->hrxWorld();
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'approved');
        $snapshot = $this->snapshot($w, $runId);
        $result = $this->inSchool($w['school'], fn () => DB::table('payroll_run_results')->where('payroll_run_id', $runId)->first());

        $refused = function (callable $statement) use ($w): string {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
            try {
                DB::connection('pgsql')->transaction($statement);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            } finally {
                DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
            }
        };
        $this->assertStringContainsString('permission denied', $refused(fn () => DB::update('update payroll_run_hrx_inputs set recorded_absence_half_units = 0 where id = ?', [$snapshot->id])));
        $this->assertStringContainsString('permission denied', $refused(fn () => DB::delete('delete from payroll_run_hrx_inputs where id = ?', [$snapshot->id])));
        $this->assertStringContainsString('payroll_hrx_input', $refused(fn () => DB::insert(
            "insert into payroll_run_hrx_inputs (id, school_id, payroll_run_result_id, payroll_run_id, employment_record_id, contract_version, fingerprint, completeness, incomplete_reasons, period_starts_on, period_ends_on, approved_paid_leave_half_units, approved_unpaid_leave_half_units, recorded_absence_half_units, recorded_presence_half_units, unresolved_working_half_units, evidence, captured_by_user_id) values (?, ?, ?, ?, ?, 'hrx_payroll_input.v1', ?, 'complete', '[]', '2026-09-01', '2026-09-30', 0, 0, 0, 0, 0, '[]', ?)",
            [(string) Str::uuid7(), $w['school']->id, $result->id, $runId, $w['employment']->id, str_repeat('a', 64), $w['admin']->id],
        )), 'one snapshot per result, and none added to a frozen run');
    }

    #[Test]
    public function part_month_coverage_and_the_manual_override_are_never_composed_into_a_double_reduction(): void
    {
        $w = $this->hrxWorld();
        $joiner = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2026-09-16', 'ends_on' => null]);
        $runId = $this->payrollRun($w['school'], [$joiner], '2026-09-01', 'draft');
        $this->approvedLeaveOf($w, '2026-09-21', '2026-09-21', $w['unpaid'], employment: $joiner);
        $this->recordAttendance($w, '2026-09-22', 'absent', 'absent', $joiner);
        $preparer = $this->preparer($w);

        $this->inSchool($w['school'], function () use ($w, $runId, $joiner, $preparer) {
            $run = PayrollRun::query()->findOrFail($runId);
            $component = SalaryComponent::query()->where('school_id', $w['school']->id)->firstOrFail();
            app(PayrollRunAdministrationService::class)->recordManualOverride($run, $joiner, [$component->id => '20000.00'], 'Joined mid-month', $preparer);
            app(PayrollRunAdministrationService::class)->calculate($run, $preparer);
        });

        $result = $this->inSchool($w['school'], fn () => DB::table('payroll_run_results')->where('payroll_run_id', $runId)->where('employment_record_id', $joiner->id)->first());
        $this->assertSame('20000.00', $result->net_amount, 'the manual part-month override stays authoritative for money; HRX evidence reduced nothing');
        $snapshot = $this->snapshot($w, $runId, $joiner);
        $this->assertSame(['2026-09-16', '2026-09-30', 2, 2], [$snapshot->covered_from, $snapshot->covered_to, (int) $snapshot->approved_unpaid_leave_half_units, (int) $snapshot->recorded_absence_half_units],
            'coverage first (nothing before joining), then absence within it -- separate inputs');
    }
}
