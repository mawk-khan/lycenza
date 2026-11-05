<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * OD-HR-1: a 2,100-entry run reviewed against a 2,100-entry previous run.
 * Every changed entry is reachable through the pages (none twice, none
 * missed), the totals equal the sum of the stored results (worked out here
 * from the synthetic inputs, never by a second engine), and another School's
 * run never appears. Synthetic data only.
 */
class PayrollApprovalReviewLargeRunTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const int ENTRIES = 2100;

    #[Test]
    public function every_changed_entry_is_reachable_and_the_totals_reconcile(): void
    {
        $school = $this->createSchool();
        app(TenantContext::class)->set($school);
        $checker = $this->createUserWithCapabilities($school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view']);
        $preparer = $this->createUser();
        $now = now();
        $component = (string) Str::uuid7();
        $august = (string) Str::uuid7();
        $september = (string) Str::uuid7();
        $previous = (string) Str::uuid7();
        $current = (string) Str::uuid7();
        $rows = ['employees' => [], 'employment_records' => [], 'payroll_run_results' => [], 'payroll_run_result_lines' => []];
        $expected = ['current' => 0, 'previous' => 0, 'changed' => 0];

        DB::table('salary_components')->insert(['id' => $component, 'school_id' => $school->id, 'code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning', 'currency' => 'INR', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('payroll_periods')->insert([
            ['id' => $august, 'school_id' => $school->id, 'period_month' => '2015-08-01', 'starts_on' => '2015-08-01', 'ends_on' => '2015-08-31', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $september, 'school_id' => $school->id, 'period_month' => '2015-09-01', 'starts_on' => '2015-09-01', 'ends_on' => '2015-09-30', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('payroll_runs')->insert([
            ['id' => $previous, 'school_id' => $school->id, 'payroll_period_id' => $august, 'run_kind' => 'regular', 'status' => 'calculated', 'prepared_by_user_id' => $preparer->id, 'approved_by_user_id' => null, 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $current, 'school_id' => $school->id, 'payroll_period_id' => $september, 'run_kind' => 'regular', 'status' => 'calculated', 'prepared_by_user_id' => $preparer->id, 'approved_by_user_id' => null, 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach (range(1, self::ENTRIES) as $i) {
            $employee = (string) Str::uuid7();
            $employment = (string) Str::uuid7();
            $rows['employees'][] = ['id' => $employee, 'school_id' => $school->id, 'employee_number' => sprintf('PAY-%05d', $i), 'full_name' => sprintf('Pay %05d', $i), 'record_status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $rows['employment_records'][] = ['id' => $employment, 'school_id' => $school->id, 'employee_id' => $employee, 'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2014-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $before = 30000 + $i;            // whole rupees, so the expected sums are exact integers
            $after = $i % 2 === 0 ? $before + 100 : $before; // every second entry changes
            foreach ([[$previous, $before], [$current, $after]] as [$run, $amount]) {
                $result = (string) Str::uuid7();
                $rows['payroll_run_results'][] = ['id' => $result, 'school_id' => $school->id, 'payroll_run_id' => $run, 'employment_record_id' => $employment, 'employee_id' => $employee, 'gross_amount' => "{$amount}.00", 'total_deductions' => '0.00', 'net_amount' => "{$amount}.00", 'created_at' => $now, 'updated_at' => $now];
                $rows['payroll_run_result_lines'][] = ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'payroll_run_result_id' => $result, 'salary_component_id' => $component, 'amount' => "{$amount}.00", 'effect' => 'increase', 'currency' => 'INR', 'created_at' => $now, 'updated_at' => $now];
            }
            $expected['previous'] += $before;
            $expected['current'] += $after;
            $expected['changed'] += (int) ($after !== $before);
        }
        foreach ($rows as $table => $data) {
            foreach (array_chunk($data, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $previousCalculation = (string) Str::uuid7();
        DB::table('payroll_run_calculations')->insert([
            ['id' => $previousCalculation, 'school_id' => $school->id, 'payroll_run_id' => $previous, 'calculated_by_user_id' => $preparer->id, 'ordinal' => 1, 'adjustment_count' => 0, 'complete' => true],
            ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'payroll_run_id' => $current, 'calculated_by_user_id' => $preparer->id, 'ordinal' => 1, 'adjustment_count' => 0, 'complete' => true],
        ]);
        // August is approved through the guarded transition, bound to its calculation.
        DB::table('payroll_runs')->where('id', $previous)->update(['status' => 'approved', 'approved_by_user_id' => $checker->id, 'approved_at' => '2015-08-25 06:00:00', 'approved_calculation_id' => $previousCalculation]);
        DB::statement('SET LOCAL enable_nestloop = off'); // no planner statistics for uncommitted bulk rows (see HrReportsLargePopulationTest)

        $reviews = app(PayrollApprovalReviewService::class);
        $first = $reviews->packet($school, $current, $checker);
        $this->assertSame([number_format($expected['current'], 2, '.', ''), number_format($expected['previous'], 2, '.', ''), self::ENTRIES, true], [$first['totals']['net'], $first['previousTotals']['net'], $first['totals']['entries'], $first['totals']['reconciles']]);
        $this->assertSame(number_format($expected['current'], 2, '.', ''), $first['components'][0]['amount'], 'component totals equal the run total');
        $this->assertSame($expected['changed'], $first['changes']->total());

        $seen = [];
        for ($page = 1; $page <= $first['changes']->lastPage(); $page++) {
            foreach ($reviews->packet($school, $current, $checker, ['changes' => $page])['changes']->items() as $row) {
                $this->assertArrayNotHasKey($row['key'], $seen, 'no entry on two pages');
                $this->assertSame('changed', $row['kind']);
                $seen[$row['key']] = true;
            }
        }
        $this->assertCount($expected['changed'], $seen, 'every changed entry was reachable');

        // Another School's checker never reads this run.
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['payroll.runs.approve', 'payroll.compensation.sensitive.view']);
        $this->expectException(ModelNotFoundException::class);
        $reviews->packet($other, $current, $outsider);
    }
}
