<?php

namespace Tests\Feature\Payroll;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO17-N1 (review M-1): the same 10,000-entry reversal, on the paths the alias check makes expensive.
 * Every Employee has link history. A (independent) is reversed. B was approved by D, an account KNOWN to be every
 * Employee's (D shares a duplicate Employee with the linked account); C by the changes' author; Z by an independent
 * checker for half of the Employees, so for those rows the alias relation is asked and answers "no" (Z discharges
 * them). B and C are found, each paying the other half first; the per-row form of this check exceeded 30 s here.
 *
 * Kept from the original:
 * Both runs are found and every change is reachable, with no cap, and the query
 * count does not depend on the size. Discovery runs under the School's exclusive
 * compensation lock, and two earlier forms of it were quadratic: 116 s at 5,000
 * entries, then over 60 s at 10,000 on a generic plan with planner statistics
 * (HR.R1-C7-R1). So the fixture is analyzed, every Employee has link history, and
 * discovery runs under a statement timeout that only a quadratic plan reaches. Synthetic data, as the runtime role, in one
 * transaction that always rolls back.
 */
class PayrollKnownSameLargePopulationTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const int ENTRIES = 10000;

    #[Test]
    public function an_alias_approved_run_is_found_and_an_independent_one_is_not_within_the_timeout(): void
    {
        $school = $this->createSchool();
        $x = $this->createUserWithCapabilities($school, ['payroll.runs.view']);
        $checker = $this->createUserWithCapabilities($school, ['payroll.runs.view']);
        $prep = $this->createUserWithCapabilities($school, ['payroll.runs.view']);
        $former = $this->createUser();
        $d = $this->createUser();
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
            $ids = ['a' => (string) Str::uuid7(), 'b' => (string) Str::uuid7(), 'c' => (string) Str::uuid7(), 'z' => (string) Str::uuid7()];
            $calc = ['a' => (string) Str::uuid7(), 'b' => (string) Str::uuid7(), 'c' => (string) Str::uuid7(), 'z' => (string) Str::uuid7()];
            $admin->statement("CREATE TEMP TABLE e ON COMMIT DROP AS SELECT g, gen_random_uuid() AS emp, gen_random_uuid() AS rec, gen_random_uuid() AS asg FROM generate_series(1, $n) g");
            $admin->statement("INSERT INTO employees (id, school_id, employee_number, full_name, record_status, created_at, updated_at) SELECT emp,'$sid', 'E'||g, 'Emp '||g, 'active', now(), now() FROM e");
            $admin->statement("INSERT INTO employment_records (id, school_id, employee_id, employment_type, staff_type, status, starts_on, created_at, updated_at) SELECT rec,'$sid',emp,'permanent','teaching','active','2014-01-01',now(),now() FROM e");
            $admin->statement("INSERT INTO employee_compensation_assignments (id, school_id, employment_record_id, salary_structure_id, effective_from, assigned_by_user_id, created_at, updated_at) SELECT asg,'$sid',rec,'$s','2015-09-01','{$x->id}',now(),now() FROM e");
            // A: independently approved, then reversed. B: by D (known to be every Employee). C: by the author. Z: independent, half the Employees.
            $half = intdiv($n, 2);
            foreach ([['a', '2015-09-01', $checker->id, '2015-09-30', $n], ['b', '2015-10-01', $d->id, '2015-10-31', $n], ['c', '2015-11-01', $x->id, '2015-11-30', $n], ['z', '2015-12-01', $checker->id, '2015-12-31', $half]] as [$k, $m, $by, $end, $upTo]) {
                $p = (string) Str::uuid7();
                $admin->statement("INSERT INTO payroll_periods (id, school_id, period_month, starts_on, ends_on, status, created_at, updated_at) VALUES ('$p','$sid','$m','$m','$end','open',now(),now())");
                $admin->statement("INSERT INTO payroll_run_calculations (id, school_id, payroll_run_id, calculated_by_user_id, ordinal, adjustment_count, complete, compensation_sources_recorded) VALUES ('{$calc[$k]}','$sid','{$ids[$k]}','{$prep->id}',1,0,true,true)");
                $admin->statement("INSERT INTO payroll_runs (id, school_id, payroll_period_id, run_kind, status, prepared_by_user_id, approved_by_user_id, approved_at, approved_calculation_id, posted_by_user_id, posted_at, created_at, updated_at) VALUES ('{$ids[$k]}','$sid','$p','regular','posted','{$prep->id}','$by',now(),'{$calc[$k]}','{$prep->id}',now(),now(),now())");
                $admin->statement("INSERT INTO payroll_run_calculation_sources (id, school_id, payroll_run_id, payroll_run_calculation_id, compensation_assignment_id, employment_record_id, effective_from, basis) SELECT gen_random_uuid(),'$sid','{$ids[$k]}','{$calc[$k]}',asg,rec,'2015-09-01','structure' FROM e WHERE g > ".($k === 'z' ? $n - $upTo : 0));
            }
            $orig = (string) Str::uuid7();
            $rev = (string) Str::uuid7();
            $admin->statement("INSERT INTO payroll_run_postings (id, school_id, payroll_run_id, journal_entry_id, currency, posting_kind, actor_user_id, created_at) VALUES ('$orig','$sid','{$ids['a']}',gen_random_uuid(),'INR','original','{$prep->id}',now())");
            $admin->statement("INSERT INTO payroll_run_postings (id, school_id, payroll_run_id, journal_entry_id, currency, posting_kind, reversal_of_payroll_run_posting_id, actor_user_id, created_at) VALUES ('$rev','$sid','{$ids['a']}',gen_random_uuid(),'INR','reversal','$orig','{$prep->id}',now())");
            foreach ([$prep, $former] as $linked) {
                $admin->statement("INSERT INTO employee_account_links (id, school_id, employee_id, user_id, origin, linked_at, ended_at) SELECT gen_random_uuid(),'$sid',emp,'{$linked->id}','recorded',now() - interval '2 days',now() - interval '1 day' FROM e");
            }
            // D and PREP share a duplicate Employee: D is known to be PREP, who is linked to every Employee.
            $dup = (string) Str::uuid7();
            $admin->statement("INSERT INTO employees (id, school_id, employee_number, full_name, record_status, created_at, updated_at) VALUES ('$dup','$sid','DUP','Duplicate','active',now(),now())");
            foreach ([$prep->id, $d->id] as $u) {
                $admin->statement("INSERT INTO employee_account_links (id, school_id, employee_id, user_id, origin, linked_at, ended_at) VALUES (gen_random_uuid(),'$sid','$dup','$u','recorded',now() - interval '2 days',now() - interval '1 day')");
            }
            $admin->statement("SET LOCAL session_replication_role = 'origin'");
            $admin->statement('ANALYZE employee_account_links, employees, employment_records, employee_compensation_assignments, payroll_runs, payroll_run_calculation_sources, payroll_run_postings');
            app(TenantContext::class)->set($school);
            $admin->statement('SET LOCAL ROLE school_os_app');
            $admin->select("select set_config('app.current_school_id', ?, true)", [$sid]);
            $admin->statement("SET LOCAL statement_timeout = '30s'");

            $timed = function (string $sql, array $bindings) use ($admin): array {
                $t = hrtime(true);
                $rows = $admin->select($sql, $bindings);

                return [$rows, round((hrtime(true) - $t) / 1e6)];
            };
            [$found, $discovery] = $timed('SELECT * FROM payroll_reversal_affected_runs(?, ?)', [$sid, $rev]);
            $expected = [$ids['b'], $ids['c']];
            sort($expected);
            $got = array_column($found, 'payroll_run_id');
            sort($got);
            $this->assertSame($expected, $got, 'the alias-approved run and the author-approved run; never the independent one');
            [$exception, $exceptionMs] = $timed('SELECT count(*) AS c FROM payroll_run_independence_exception(?, ?)', [$sid, $ids['b']]);
            $this->assertSame($n - $half, (int) $exception[0]->c, 'every change B pays first (Z discharged the rest) is its approver\'s own');
            [$none] = $timed('SELECT count(*) AS c FROM payroll_run_independence_exception(?, ?)', [$sid, $ids['z']]);
            $this->assertSame(0, (int) $none[0]->c, 'the independent run is never in an exception');
            [$firstPaid, $firstPaidMs] = $timed('SELECT count(*) AS c FROM payroll_run_first_paid_compensation(?, ?, ?)', [$sid, $ids['c'], $calc['c']]);
            $this->assertSame($n - $half, (int) $firstPaid[0]->c, 'B (an account known to be the Employees) discharges nothing; Z discharges its half; A is reversed');
            fwrite(STDERR, "WO17N1-SCALE entries={$n} discovery={$discovery}ms exception={$exceptionMs}ms firstPaid={$firstPaidMs}ms\n");
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
            app(TenantContext::class)->clearAll();
        }

        $this->deleteSchoolAsAdmin($school);
    }
}
