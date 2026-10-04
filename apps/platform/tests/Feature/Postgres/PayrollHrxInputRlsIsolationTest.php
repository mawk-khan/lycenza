<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.8, CLAUDE.md rule 28): `payroll_run_hrx_inputs` is
 * forced-RLS and same-School by construction -- proven with raw SQL as the
 * runtime role.
 */
class PayrollHrxInputRlsIsolationTest extends TestCase
{
    use CreatesPayrollHrxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function context(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    #[Test]
    public function the_snapshot_table_is_isolated_by_forced_rls_and_same_school_keys(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payroll_run_hrx_inputs'");
        $this->assertTrue((bool) $row->relrowsecurity && (bool) $row->relforcerowsecurity);

        $a = $this->hrxWorld();
        $b = $this->hrxWorld();
        $runA = $this->payrollRun($a['school'], [$a['employment']], '2026-09-01', 'calculated');
        $runB = $this->payrollRun($b['school'], [$b['employment']], '2026-09-01', 'calculated');
        $resultA = $this->inSchool($a['school'], fn () => DB::table('payroll_run_results')->where('payroll_run_id', $runA)->first());

        $this->context(null);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from payroll_run_hrx_inputs')->c, 'no context, no rows');
        $this->context($b['school']->id);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from payroll_run_hrx_inputs where school_id = ?', [$a['school']->id])->c, 'School B sees none of A');
        $this->assertSame(1, (int) DB::selectOne('select count(*) as c from payroll_run_hrx_inputs')->c);

        // School B cannot insert a snapshot naming School A, nor point its own at A's result: A's run is invisible
        // to the guard trigger under RLS, and the RLS write check and the composite key refuse it too.
        foreach ([[$a['school']->id, $resultA->id, $runA, $a['employment']->id], [$b['school']->id, $resultA->id, $runA, $a['employment']->id]] as [$schoolId, $resultId, $runId, $employmentId]) {
            try {
                DB::connection('pgsql')->transaction(fn () => DB::insert(
                    "insert into payroll_run_hrx_inputs (id, school_id, payroll_run_result_id, payroll_run_id, employment_record_id, contract_version, fingerprint, completeness, incomplete_reasons, period_starts_on, period_ends_on, approved_paid_leave_half_units, approved_unpaid_leave_half_units, recorded_absence_half_units, recorded_presence_half_units, unresolved_working_half_units, evidence, captured_by_user_id) values (?, ?, ?, ?, ?, 'hrx_payroll_input.v1', ?, 'complete', '[]', '2026-09-01', '2026-09-30', 0, 0, 0, 0, 0, '[]', ?)",
                    [(string) Str::uuid7(), $schoolId, $resultId, $runId, $employmentId, str_repeat('b', 64), $b['admin']->id],
                ));
                $this->fail('a cross-School snapshot was accepted');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/row-level security|payroll_run_hrx_inputs_result_fk|payroll_run_hrx_inputs_one_per_result|payroll_hrx_input_invalid/', $e->getMessage());
            }
        }
        // No runtime UPDATE at all (append-only), let alone across Schools.
        try {
            DB::connection('pgsql')->transaction(fn () => DB::update("update payroll_run_hrx_inputs set completeness = 'complete' where school_id = ?", [$a['school']->id]));
            $this->fail('an update was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
        $this->context(null);
        $this->assertNotNull($runB);
    }
}
