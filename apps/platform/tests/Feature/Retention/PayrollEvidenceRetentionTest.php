<?php

namespace Tests\Feature\Retention;

use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\Retention\PayrollEvidenceRetentionService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\TenantClosureReadiness;
use App\Support\Retention\TenantRetentionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3F (E21-D9): posted payroll evidence expires 8 calendar years after
 * the Employee's final separation (EmployeeRetentionEligibility), once every
 * run holding it and every posting is that old too; an emptied run then
 * loses its postings and runs, releasing its journal entries to Finance.
 * Nothing in the ledger changes.
 */
class PayrollEvidenceRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePayrollRetention();
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:payroll-retention-prune', $options);
    }

    private function recordOf(School $school, string $employeeId): string
    {
        return (string) $this->inSchool($school, fn () => DB::table('employment_records')->where('employee_id', $employeeId)->value('id'));
    }

    private function results(School $school, string $employeeId): int
    {
        return $this->rowsWhere($school, 'payroll_run_results', 'employee_id', $employeeId);
    }

    /** The School-local cutoff the command uses (real today minus 8 calendar years). */
    private function cutoff(School $school): string
    {
        return CarbonImmutable::now($school->timezone)->subYearsNoOverflow(8)->toDateString();
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_a_period_under_eight_years_fails(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $leaver = $this->paidEmployee($school, $setup);
        $this->postedRun($school, '2015-08-01', '2015-08-31');
        $this->separate($leaver, '2015-12-31');

        config(['retention.employee_evidence_years' => null]);
        $this->prune()->expectsOutputToContain('not configured')->assertSuccessful();
        config(['retention.employee_evidence_years' => 6]);
        $this->prune()->expectsOutputToContain('at least 8')->assertFailed();
        config(['retention.employee_evidence_years' => 'eight']);
        $this->prune()->assertFailed();

        $this->assertSame(1, $this->results($school, $leaver->id));
    }

    #[Test]
    public function posted_evidence_expires_with_its_lines_statutory_results_and_lwf_charges_and_the_emptied_run_releases_its_entries(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->statutorySetup($school, $setup);
        $leaver = $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31', statutory: true);
        $this->separate($leaver, '2015-12-31');
        $entries = $this->runEntries($school, $run->id);
        $this->assertCount(2, $entries, 'a payroll and a statutory posting');
        $result = $this->inSchool($school, fn () => DB::table('payroll_run_results')->where('employee_id', $leaver->id)->value('id'));
        $balances = $this->balances($school);

        $this->prune()
            ->expectsOutputToContain('Deleted the payroll evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 0, errors: 0)')
            ->expectsOutputToContain('Deleted 1 emptied payroll run(s)')
            ->assertSuccessful();

        $this->assertSame(0, $this->results($school, $leaver->id));
        foreach (['payroll_run_result_lines', 'payroll_statutory_calculation_results', 'payroll_lwf_annual_charges'] as $table) {
            $this->assertSame(0, $this->rowsWhere($school, $table, 'payroll_run_result_id', $result), "{$table} went with the result");
        }
        $this->assertSame(0, $this->rowsWhere($school, 'payroll_runs', 'id', $run->id), 'the emptied run');
        $this->assertSame(0, $this->rowsWhere($school, 'payroll_run_postings', 'payroll_run_id', $run->id));
        $this->assertSame(0, $this->rowsWhere($school, 'payroll_statutory_run_postings', 'payroll_run_id', $run->id));
        foreach ($entries as $entry) {
            $this->assertSame(1, $this->rowsWhere($school, 'journal_entries', 'id', $entry), 'Payroll never deletes a journal entry: Finance owns it');
        }
        $this->assertSame($balances, $this->balances($school), 'every account balance, payroll accounts included, is exactly unchanged');
        $this->assertSame(1, $this->rowsWhere($school, 'payroll_periods', 'school_id', $school->id), 'payroll periods are School configuration');
        $this->assertSame(1, $this->rowsWhere($school, 'employees', 'id', $leaver->id), 'Payroll never deletes the Employee');

        // A second run finds nothing new and does not fail.
        $this->prune()
            ->expectsOutputToContain('Deleted the payroll evidence of 0 Employee(s)')
            ->expectsOutputToContain('Deleted 0 emptied payroll run(s)')
            ->assertSuccessful();
    }

    #[Test]
    public function final_separation_drives_the_clock_and_current_future_rehired_young_and_ambiguous_employees_keep_everything(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $staff = [];
        foreach (['active', 'notice', 'rehired', 'young', 'boundary', 'due', 'ambiguous'] as $case) {
            $staff[$case] = $this->paidEmployee($school, $setup);
        }
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31');
        $cutoff = $this->cutoff($school);

        // A notice-period record with an end date (written through the schema owner: since E21-RH.6 no workflow
        // ends a record without a terminal status, and the guard refuses the runtime role).
        $this->separate($staff['notice'], '2015-12-31', 'notice_period');
        $this->separate($staff['rehired'], '2015-12-31');
        $this->createEmploymentRecord($staff['rehired'], ['status' => 'active', 'starts_on' => '2022-01-01', 'ends_on' => null]);
        $this->separate($staff['young'], '2020-06-30');
        $this->separate($staff['boundary'], $cutoff);
        $this->separate($staff['due'], CarbonImmutable::parse($cutoff)->subDay()->toDateString());
        $this->separate($staff['ambiguous'], null);

        $this->prune()
            ->expectsOutputToContain('Deleted the payroll evidence of 1 Employee(s) (unresolved separation: 1, dependency-blocked: 0')
            ->expectsOutputToContain('Deleted 0 emptied payroll run(s)')
            ->assertSuccessful();

        $this->assertSame(0, $this->results($school, $staff['due']->id), 'the day before the boundary: 8 calendar years have passed');
        foreach (['active', 'notice', 'rehired', 'young', 'boundary', 'ambiguous'] as $case) {
            $this->assertSame(1, $this->results($school, $staff[$case]->id), "{$case} keeps its payroll evidence");
        }
        $run = $this->inSchool($school, fn () => $run->fresh());
        $this->assertNotNull($run->results_expired_at, 'the run records that its detail is no longer complete');
        $this->assertSame(1, $this->rowsWhere($school, 'payroll_run_postings', 'payroll_run_id', $run->id), 'a run still holding evidence keeps its posting');
    }

    #[Test]
    public function a_late_reversal_or_correction_is_new_evidence_and_keeps_it_until_it_is_old_enough_too(): void
    {
        // Reversed today: the run's reversal posting is young.
        $reversed = $this->createSchool();
        $setup = $this->payrollSetup($reversed);
        $a = $this->paidEmployee($reversed, $setup);
        $run = $this->postedRun($reversed, '2015-08-01', '2015-08-31');
        $this->separate($a, '2015-12-31');
        $this->inSchool($reversed, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->createUser(), 'late'));

        // Corrected today: a draft correction run names the Employee.
        $corrected = $this->createSchool();
        $setup2 = $this->payrollSetup($corrected);
        $b = $this->paidEmployee($corrected, $setup2);
        $original = $this->postedRun($corrected, '2015-08-01', '2015-08-31');
        $this->separate($b, '2015-12-31');
        $this->inSchool($corrected, function () use ($corrected, $original, $b, $setup2): void {
            $runs = app(PayrollRunService::class);
            $period = PayrollPeriod::query()->findOrFail($original->payroll_period_id);
            $correction = $runs->createCorrectionRun($original->fresh(), $period, $setup2['actor']);
            $component = DB::table('salary_components')->where('school_id', $corrected->id)->value('id');
            $runs->recordCorrectionDelta($correction, $b->employmentRecords()->firstOrFail(), [new CorrectionDeltaInput($component, '100.00', 'increase')], 'arrears', $setup2['actor']);
        });

        $this->prune()
            ->expectsOutputToContain('Deleted the payroll evidence of 0 Employee(s) (unresolved separation: 0, dependency-blocked: 2')
            ->assertSuccessful();

        $this->assertSame(1, $this->results($reversed, $a->id));
        $this->assertSame(1, $this->results($corrected, $b->id));
        $this->assertSame(1, $this->rowsWhere($corrected, 'payroll_adjustments', 'employment_record_id', $this->recordOf($corrected, $b->id)));
    }

    #[Test]
    public function unposted_runs_and_unposted_statutory_results_are_working_state_and_keep_the_evidence(): void
    {
        $calculated = $this->createSchool();
        $a = $this->paidEmployee($calculated, $this->payrollSetup($calculated));
        $this->postedRun($calculated, '2015-08-01', null, approve: false);
        $this->separate($a, '2015-12-31');

        $statutory = $this->createSchool();
        $setup = $this->payrollSetup($statutory);
        $b = $this->paidEmployee($statutory, $setup);
        // Statutory results without their statutory posting: the filing is not settled.
        $this->postedRun($statutory, '2015-08-01', '2015-08-31', statutory: true, postStatutory: false);
        $this->separate($b, '2015-12-31');

        $this->prune()->expectsOutputToContain('Deleted the payroll evidence of 0 Employee(s) (unresolved separation: 0, dependency-blocked: 2')->assertSuccessful();

        $this->assertSame(1, $this->results($calculated, $a->id));
        $this->assertSame(1, $this->results($statutory, $b->id));
    }

    #[Test]
    public function a_held_school_keeps_everything_and_another_school_proceeds(): void
    {
        $held = $this->createSchool();
        $a = $this->paidEmployee($held, $this->payrollSetup($held));
        $this->postedRun($held, '2015-08-01', '2015-08-31');
        $this->separate($a, '2015-12-31');
        $free = $this->createSchool();
        $b = $this->paidEmployee($free, $this->payrollSetup($free));
        $this->postedRun($free, '2015-08-01', '2015-08-31');
        $this->separate($b, '2015-12-31');
        config(['retention.hold_school_ids' => [$held->id]]);
        // E21-RH.5: the units run as the retention identity, which refuses destructive work while a configured hold
        // is not yet recorded in the database (as HRX does); record it, as platform:retention-holds-reconcile would.
        app(RetentionHolds::class)->place($held->id, 'litigation', 'PAYROLL-HOLD');

        $this->prune()->expectsOutputToContain('Deleted the payroll evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 1')->assertSuccessful();

        $this->assertSame(1, $this->results($held, $a->id), 'held: counted only');
        $this->assertSame(0, $this->results($free, $b->id));
    }

    #[Test]
    public function a_dry_run_counts_exactly_what_a_real_run_deletes_and_changes_nothing(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $due = $this->paidEmployee($school, $setup);
        $alsoDue = $this->paidEmployee($school, $setup);
        $current = $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31');
        $this->separate($due, '2015-12-31');
        $this->separate($alsoDue, '2016-01-31');
        $before = $this->inSchool($school, fn () => DB::table('payroll_run_results')->count());

        $this->prune(['--dry-run' => true])
            ->expectsOutputToContain('Dry run: would delete the payroll evidence of 2 Employee(s)')
            ->expectsOutputToContain('Dry run: would delete 0 emptied payroll run(s)')
            ->assertSuccessful();
        $this->assertSame($before, $this->inSchool($school, fn () => DB::table('payroll_run_results')->count()), 'a dry run changes nothing');

        $this->prune()->expectsOutputToContain('Deleted the payroll evidence of 2 Employee(s)')->assertSuccessful();
        $this->assertSame(1, $this->results($school, $current->id));

        // The run phase previews against the current state: once its last Employee has gone, the
        // dry run and the real run agree on it too.
        $this->separate($current, '2016-02-29');
        app(PayrollEvidenceRetentionService::class)->pruneEvidence($school, $this->cutoff($school), 100, false);
        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 1 emptied payroll run(s)')->assertSuccessful();
        $this->assertSame(1, $this->rowsWhere($school, 'payroll_runs', 'id', $run->id));
        $this->prune()->expectsOutputToContain('Deleted 1 emptied payroll run(s)')->assertSuccessful();
        $this->assertSame(0, $this->rowsWhere($school, 'payroll_runs', 'id', $run->id));
    }

    #[Test]
    public function payroll_is_no_longer_an_indefinite_blocker_and_the_next_employee_run_releases_the_employee(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $leaver = $this->paidEmployee($school, $setup);
        $linked = $this->paidEmployee($school, $setup);
        $this->postedRun($school, '2015-08-01', '2015-08-31');
        $this->separate($leaver, '2015-12-31');
        $this->separate($linked, '2015-12-31');
        $this->inSchool($school, fn () => DB::table('employees')->where('id', $linked->id)->update(['user_id' => $this->createUser()->id]));

        // Before: payroll results keep both Employees.
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
            ->expectsOutputToContain('the employment evidence of 0 Employee(s)')->assertSuccessful();
        $this->assertSame(1, $this->rowsWhere($school, 'employees', 'id', $leaver->id));

        $this->prune()->expectsOutputToContain('Deleted the payroll evidence of 2 Employee(s)')->assertSuccessful();
        $this->assertSame(1, $this->rowsWhere($school, 'employees', 'id', $leaver->id), 'payroll retention never deletes the Employee itself');

        // The next Employee run re-evaluates: payroll configuration, then the Employee.
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
            ->expectsOutputToContain('payroll records of 2 Employee(s) and the employment evidence of 1 Employee(s)')->assertSuccessful();
        $this->assertSame(0, $this->rowsWhere($school, 'employees', 'id', $leaver->id));
        $this->assertSame(1, $this->rowsWhere($school, 'employees', 'id', $linked->id), 'other blockers (a linked User) still keep the Employee');
    }

    #[Test]
    public function after_expiry_payslips_are_not_found_the_run_says_its_detail_is_incomplete_and_statutory_exports_refuse(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->statutorySetup($school, $setup);
        $leaver = $this->paidEmployee($school, $setup);
        $stays = $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31', statutory: true);
        $this->separate($leaver, '2015-12-31');
        $record = $this->recordOf($school, $leaver->id);

        $viewer = $this->createUserWithCapabilities($school, ['payroll.runs.view', 'payroll.compensation.sensitive.view', 'payroll.statutory.exports.generate', 'payroll.statutory.view', 'payroll.statutory.identifiers.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        // SR.4 (ADR 0071 §26.7): payroll amounts need current MFA assurance.
        $this->withMfaAssurance($viewer);
        $this->actingAs($viewer)->get("/app/payroll/runs/{$run->id}/payslips/{$record}")->assertOk();

        $this->prune()->assertSuccessful();

        $this->actingAs($viewer)->get("/app/payroll/runs/{$run->id}/payslips/{$record}")->assertNotFound();
        $this->actingAs($viewer)->get("/app/payroll/runs/{$run->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('run.resultsExpiredAt', fn ($v) => $v !== null)->has('results', 1));
        foreach (['ecr', 'esi-worksheet', 'tds-draft-statement'] as $export) {
            $this->actingAs($viewer)->get("/app/payroll/statutory/runs/{$run->id}/exports/{$export}")->assertStatus(409);
        }
        $this->assertSame(1, $this->results($school, $stays->id));

        // The API says the same: the run flags it, the payslip is an ordinary not-found, exports refuse.
        $this->app['auth']->forgetGuards();
        $api = $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($viewer));
        $api->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$run->id}")->assertOk()->assertJsonPath('data.resultsExpiredAt', fn ($v) => $v !== null);
        $api->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$run->id}/payslips/{$record}")->assertNotFound();
        $api->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$run->id}/statutory-exports/ecr")->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_RESULTS_EXPIRED');
    }

    #[Test]
    public function readiness_reports_payroll_as_an_adopted_running_period_never_a_technical_blocker(): void
    {
        $school = $this->createSchool();
        $leaver = $this->paidEmployee($school, $this->payrollSetup($school));
        $this->postedRun($school, '2015-08-01', '2015-08-31');
        $this->separate($leaver, '2015-12-31');

        $report = app(TenantClosureReadiness::class)->report($school);
        $categories = collect($report['categories'])->keyBy('category');

        $this->assertSame('retained', $categories['payroll_ledger']['outcome']);
        $this->assertSame('2024-01-01', $categories['payroll_ledger']['not_before'], 'the later of the separation and the last posting, plus 8 calendar years and a day');
        $this->assertSame(TenantRetentionCatalog::TENANT_LIFETIME, $categories['payroll_calendar']['outcome']);
        $this->assertNotContains('d8_d9_payroll_ledger_retained', $report['gates']);
        $this->assertNotContains('retention_mechanism_pending', $report['gates']);
        $this->assertContains('retention_periods_running', $report['gates']);
        $this->assertFalse($report['purge_ready']);

        $this->prune()->assertSuccessful();
        $categories = collect(app(TenantClosureReadiness::class)->report($school)['categories'])->keyBy('category');
        $this->assertSame('empty', $categories['payroll_ledger']['outcome'], 'every payroll evidence row of the School has expired');
    }
}
