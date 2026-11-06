<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C6 (PAY-18): a reversal behind 2,100 entries and two dependent posted runs.
 * Both runs are found and every change is reachable, with no cap, and the query
 * count does not depend on the size. Discovery runs under the School's exclusive
 * compensation lock, and an earlier form of it was quadratic: 116 s at 5,000
 * entries. Synthetic data, as the runtime role, in one transaction that always
 * rolls back.
 */
class PayrollReconciliationLargePopulationTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const int ENTRIES = 2100;

    #[Test]
    public function every_dependent_run_and_change_is_found_in_a_bounded_number_of_queries(): void
    {
        $school = $this->createSchool();
        $x = $this->createUserWithCapabilities($school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $checker = $this->createUserWithCapabilities($school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $reviewer = $this->createUserWithCapabilities($school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $prep = $this->createUserWithCapabilities($school, ['payroll.runs.view']);
        $default = DB::getDefaultConnection();
        $sid = $school->id;
        $n = self::ENTRIES;

        $admin = DB::connection('pgsql_admin');
        $admin->beginTransaction();
        DB::setDefaultConnection('pgsql_admin');
        try {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $s = (string) Str::uuid7();
            $admin->statement("INSERT INTO salary_structures (id, school_id, code, name, version, status, created_at, updated_at) VALUES ('$s','$sid','S','S',1,'active',now(),now())");
            $ids = ['a' => (string) Str::uuid7(), 'b' => (string) Str::uuid7(), 'c' => (string) Str::uuid7()];
            $calc = ['a' => (string) Str::uuid7(), 'b' => (string) Str::uuid7(), 'c' => (string) Str::uuid7()];
            $admin->statement("CREATE TEMP TABLE e ON COMMIT DROP AS SELECT g, gen_random_uuid() AS emp, gen_random_uuid() AS rec, gen_random_uuid() AS asg FROM generate_series(1, $n) g");
            $admin->statement("INSERT INTO employees (id, school_id, employee_number, full_name, record_status, created_at, updated_at) SELECT emp,'$sid', 'E'||g, 'Emp '||g, 'active', now(), now() FROM e");
            $admin->statement("INSERT INTO employment_records (id, school_id, employee_id, employment_type, staff_type, status, starts_on, created_at, updated_at) SELECT rec,'$sid',emp,'permanent','teaching','active','2014-01-01',now(),now() FROM e");
            $admin->statement("INSERT INTO employee_compensation_assignments (id, school_id, employment_record_id, salary_structure_id, effective_from, assigned_by_user_id, created_at, updated_at) SELECT asg,'$sid',rec,'$s','2015-09-01','{$x->id}',now(),now() FROM e");
            foreach ([['a', '2015-09-01', $checker->id, '2015-09-30'], ['b', '2015-10-01', $x->id, '2015-10-31'], ['c', '2015-11-01', $x->id, '2015-11-30']] as [$k, $m, $by, $end]) {
                $p = (string) Str::uuid7();
                $admin->statement("INSERT INTO payroll_periods (id, school_id, period_month, starts_on, ends_on, status, created_at, updated_at) VALUES ('$p','$sid','$m','$m','$end','open',now(),now())");
                $admin->statement("INSERT INTO payroll_run_calculations (id, school_id, payroll_run_id, calculated_by_user_id, ordinal, adjustment_count, complete, compensation_sources_recorded) VALUES ('{$calc[$k]}','$sid','{$ids[$k]}','{$prep->id}',1,0,true,true)");
                $admin->statement("INSERT INTO payroll_runs (id, school_id, payroll_period_id, run_kind, status, prepared_by_user_id, approved_by_user_id, approved_at, approved_calculation_id, posted_by_user_id, posted_at, created_at, updated_at) VALUES ('{$ids[$k]}','$sid','$p','regular','posted','{$prep->id}','$by',now(),'{$calc[$k]}','{$prep->id}',now(),now(),now())");
                $admin->statement("INSERT INTO payroll_run_calculation_sources (id, school_id, payroll_run_id, payroll_run_calculation_id, compensation_assignment_id, employment_record_id, effective_from, basis) SELECT gen_random_uuid(),'$sid','{$ids[$k]}','{$calc[$k]}',asg,rec,'2015-09-01','structure' FROM e");
            }
            $orig = (string) Str::uuid7();
            $rev = (string) Str::uuid7();
            $admin->statement("INSERT INTO payroll_run_postings (id, school_id, payroll_run_id, journal_entry_id, currency, posting_kind, actor_user_id, created_at) VALUES ('$orig','$sid','{$ids['a']}',gen_random_uuid(),'INR','original','{$prep->id}',now())");
            $admin->statement("INSERT INTO payroll_run_postings (id, school_id, payroll_run_id, journal_entry_id, currency, posting_kind, reversal_of_payroll_run_posting_id, actor_user_id, created_at) VALUES ('$rev','$sid','{$ids['a']}',gen_random_uuid(),'INR','reversal','$orig','{$prep->id}',now())");
            $admin->statement("SET LOCAL session_replication_role = 'origin'");
            $admin->statement('ANALYZE employees, employment_records, employee_compensation_assignments, payroll_runs, payroll_run_calculation_sources, payroll_run_postings');
            app(TenantContext::class)->set($school);
            $admin->statement('SET LOCAL ROLE school_os_app');
            $admin->select("select set_config('app.current_school_id', ?, true)", [$sid]);

            $queries = function (callable $f) use ($admin): array {
                $admin->flushQueryLog();
                $admin->enableQueryLog();
                $result = $f();
                $count = count($admin->getQueryLog());
                $admin->disableQueryLog();

                return [$result, $count];
            };
            $a = PayrollRun::query()->where('school_id', $sid)->findOrFail($ids['a']);
            $posting = PayrollRunPosting::query()->where('school_id', $sid)->findOrFail($rev);
            [$found] = $queries(fn () => $admin->select('SELECT * FROM payroll_reversal_affected_runs(?, ?)', [$sid, $rev]));
            $this->assertCount(2, $found, 'both dependent runs, with no truncation');

            [$count, $recording] = $queries(fn () => app(PayrollPostingIndependence::class)->recordAffectedBy($a, $posting, $prep));
            $this->assertSame(2, $count);
            $this->assertLessThanOrEqual(18, $recording, 'measured flat from 500 to 10,000 entries');

            $o = PayrollReversalReconciliation::query()->where('school_id', $sid)->where('payroll_run_id', $ids['b'])->firstOrFail();
            [$show, $reading] = $queries(fn () => app(PayrollReversalReconciliationReadService::class)->show($school, $reviewer, $o->id, true));
            $this->assertSame($n, $show['details']['changes']['total'], 'every change is reachable through the pages');
            $this->assertSame($n, $show['details']['employeesWithoutAccount']);
            $this->assertCount(25, $show['details']['changes']['data'], 'never the whole run on one page');
            $this->assertLessThanOrEqual(36, $reading, 'a cold capability cache included; flat from 500 to 10,000 entries');

            $version = app(PayrollReversalReconciliationService::class)->version($o);
            [, $reviewing] = $queries(fn () => app(PayrollReversalReconciliationService::class)->review($school, $o->id, $reviewer, new FreshMfaProof($reviewer->id, CarbonImmutable::now()), $version, true));
            $this->assertLessThanOrEqual(37, $reviewing);
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
            app(TenantContext::class)->clearAll();
        }

        $this->deleteSchoolAsAdmin($school);
    }
}
