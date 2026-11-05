<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C1 (PAY-2, PAY-3, PAY-4, PAY-5): the approval guard is not an
 * application convention. Every refusal here is a RAW UPDATE by the runtime
 * role (`school_os_app`, the role every request uses), bypassing the services
 * entirely -- `payroll_runs_approval_evidence_guard` and the result/line
 * rewrite guards refuse it. Synthetic 2015 data.
 */
class PayrollCompensationProvenanceDbTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    private School $school;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    private User $checker;

    private User $x;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail());
        $this->checker = $this->createUser();
        $this->x = $this->createUser();
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $preparer = $this->createUser();
        $this->run = $this->inSchool($this->school, function () use ($preparer): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2015-10-01'), null, $preparer), $preparer);
            $run = app(PayrollRunService::class)->createRun($period, $preparer);
            app(PayrollRunService::class)->calculate($run, $preparer);

            return $run->fresh();
        });
    }

    private function raise(string $from, User $by): void
    {
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign($this->school, $this->record->fresh(), $this->setup['structure'],
            Carbon::parse($from), [new FixedComponentValueInput($this->setup['line'], '55000.00')], $by, $this->freshMfaProofFor($by)));
    }

    private function latest(): string
    {
        return (string) $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($this->run));
    }

    private function recalculate(): void
    {
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->calculate($this->run->fresh(), $this->createUser()));
    }

    /** A raw approval UPDATE, as the runtime role, never through the service. */
    private function rawApprove(User $by): void
    {
        $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->run->id)->update([
            'status' => 'approved', 'approved_by_user_id' => $by->id, 'approved_at' => now(), 'approved_calculation_id' => $this->latest(),
        ]));
    }

    private function assertRefused(string $message, callable $write): void
    {
        try {
            DB::transaction(fn () => $write()); // a savepoint: the refusal does not abort the test's transaction
            $this->fail("not refused: {$message}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function the_runtime_role_is_the_application_role(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
    }

    #[Test]
    public function a_raw_approval_of_a_calculation_overtaken_by_a_compensation_change_of_its_period_is_refused(): void
    {
        $this->raise('2015-10-01', $this->x);
        $this->assertRefused('period compensation changed after its calculation', fn () => $this->rawApprove($this->checker));
    }

    #[Test]
    public function a_raw_approval_by_the_author_of_a_change_the_run_first_pays_is_refused(): void
    {
        $this->raise('2015-10-01', $this->x);
        $this->recalculate();
        $this->assertRefused('approved by the author of a compensation change it first pays', fn () => $this->rawApprove($this->x));
        // ...and an independent checker passes the same guard.
        $this->rawApprove($this->checker);
        $this->assertSame('approved', $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->run->id)->value('status')));
    }

    #[Test]
    public function a_raw_approval_of_a_calculation_that_recorded_no_provenance_is_refused(): void
    {
        // A calculation as it was written before HR.R1-C1 (no sources), the latest of the run.
        $this->inSchool($this->school, fn () => DB::table('payroll_run_calculations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $this->run->id, 'calculated_by_user_id' => $this->createUser()->id,
            'ordinal' => 2, 'adjustment_count' => 0, 'complete' => true, 'compensation_sources_recorded' => false, 'created_at' => now(),
        ]));
        $this->assertRefused('calculated before compensation provenance was recorded', fn () => $this->rawApprove($this->checker));
    }

    #[Test]
    public function results_that_do_not_reconcile_with_their_lines_are_refused_at_approval(): void
    {
        $this->inSchool($this->school, function (): void {
            $result = DB::table('payroll_run_results')->where('payroll_run_id', $this->run->id)->first(['id']);
            // A raw extra earning line the result's gross does not include.
            $component = (string) Str::uuid7();
            DB::table('salary_components')->insert(['id' => $component, 'school_id' => $this->school->id, 'code' => 'EXTRA', 'name' => 'Extra', 'type' => 'earning', 'currency' => 'INR', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payroll_run_result_lines')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_result_id' => $result->id,
                'salary_component_id' => $component, 'amount' => '1.00', 'effect' => 'increase', 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertRefused('results do not reconcile with their lines', fn () => $this->rawApprove($this->checker));
    }

    #[Test]
    public function a_calculated_result_or_line_is_never_rewritten(): void
    {
        $this->assertRefused('a calculated result is never rewritten', fn () => $this->inSchool($this->school,
            fn () => DB::table('payroll_run_results')->where('payroll_run_id', $this->run->id)->update(['net_amount' => DB::raw('net_amount + 1'), 'gross_amount' => DB::raw('gross_amount + 1')])));
        $this->assertRefused('a calculated line is never rewritten', fn () => $this->inSchool($this->school,
            fn () => DB::table('payroll_run_result_lines')->where('school_id', $this->school->id)->update(['amount' => DB::raw('amount + 1')])));
    }

    #[Test]
    public function results_that_are_not_the_approved_calculations_output_are_refused(): void
    {
        $this->inSchool($this->school, fn () => DB::table('payroll_run_calculations')->insert([
            'id' => $other = (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $this->run->id, 'calculated_by_user_id' => $this->createUser()->id,
            'ordinal' => 2, 'adjustment_count' => 0, 'complete' => true, 'compensation_sources_recorded' => true, 'created_at' => now(),
        ]));
        // The latest calculation is now one that produced none of the run's results.
        $this->assertRefused('results are not those of the approved calculation', fn () => $this->rawApprove($this->checker));
    }

    #[Test]
    public function recorded_provenance_is_append_only_for_the_runtime_role(): void
    {
        $this->assertRefused('permission denied', fn () => $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->where('payroll_run_id', $this->run->id)->update(['basis' => 'period_override'])));
        $this->assertRefused('permission denied', fn () => $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->where('payroll_run_id', $this->run->id)->delete()));
        $this->assertTrue($this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->where('payroll_run_id', $this->run->id)->exists()), 'a regular run records its sources');
    }

    #[Test]
    public function another_schools_provenance_is_invisible_without_its_context(): void
    {
        $other = $this->createSchool();
        $this->assertSame(0, $this->inSchool($other, fn () => DB::table('payroll_run_calculation_sources')->count()));
        $this->assertGreaterThan(0, $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->count()));
    }

    #[Test]
    public function provenance_cannot_be_forged_onto_an_approved_run_or_an_old_calculation(): void
    {
        $this->rawApprove($this->checker);
        [$calc, $assignment] = $this->inSchool($this->school, fn () => [
            (string) DB::table('payroll_runs')->where('id', $this->run->id)->value('approved_calculation_id'),
            DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->first(['id', 'effective_from', 'effective_to']),
        ]);
        // A later raise X records; a forged "already paid" row on the APPROVED run would hide it.
        $this->raise('2015-11-01', $this->x);
        $raise = $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->orderByDesc('effective_from')->first(['id', 'effective_from', 'effective_to']));
        $row = fn (string $calculationId, object $a) => ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $this->run->id,
            'payroll_run_calculation_id' => $calculationId, 'compensation_assignment_id' => $a->id, 'employment_record_id' => $this->record->id,
            'effective_from' => $a->effective_from, 'effective_to' => $a->effective_to, 'basis' => 'structure', 'created_at' => now()];
        $this->assertRefused('its provenance is final', fn () => $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->insert($row($calc, $raise))));
        $this->assertTrue((bool) $assignment, 'the run recorded its own source');
    }

    #[Test]
    public function provenance_records_the_assignment_exactly_and_only_for_the_latest_calculation(): void
    {
        $first = $this->latest();
        $this->recalculate();
        $assignment = $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->first(['id', 'effective_from', 'effective_to']));
        $base = ['school_id' => $this->school->id, 'payroll_run_id' => $this->run->id, 'compensation_assignment_id' => $assignment->id,
            'employment_record_id' => $this->record->id, 'basis' => 'structure', 'created_at' => now()];
        $this->assertRefused("only the run's latest calculation records provenance", fn () => $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->insert(
            $base + ['id' => (string) Str::uuid7(), 'payroll_run_calculation_id' => $first, 'effective_from' => $assignment->effective_from, 'effective_to' => $assignment->effective_to])));
        $this->assertRefused('records the assignment exactly as it is', fn () => $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->insert(
            $base + ['id' => (string) Str::uuid7(), 'payroll_run_calculation_id' => $this->latest(), 'effective_from' => '2015-10-01', 'effective_to' => null])));
    }
}
