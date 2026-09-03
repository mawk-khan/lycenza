<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * Phase 9.1 (ADR 0034) -- raw-DB-level proof of every schema invariant
 * this checkpoint introduces.
 *
 * All fixtures AND assertions here go through `pgsql_admin` exclusively
 * -- this environment's `pgsql` (application-role) and `pgsql_admin`
 * (superuser) Laravel connections are genuinely separate PostgreSQL
 * sessions under `DatabaseTransactions` (confirmed directly: a row
 * inserted via `pgsql` inside a test is invisible to `pgsql_admin`,
 * including for foreign-key constraint checks, not merely for
 * `SELECT`). Mixing an Eloquent-factory-via-`pgsql` fixture with a
 * `pgsql_admin`-referenced assertion is consequently unreliable here
 * regardless of which table is involved -- two existing tests in
 * `HostelResidencyAssignmentsRlsIsolationTest` do exactly that mix and
 * still report green today only because they assert "some
 * QueryException was thrown" without distinguishing a genuine
 * cross-School rejection from this cross-connection-visibility gap
 * (a pre-existing, latent test-authoring risk, not a Payroll defect,
 * and out of this checkpoint's scope to fix). Using one connection
 * throughout, exactly like the standalone raw-SQL script that first
 * proved these invariants, sidesteps the issue entirely and is what
 * every test below does.
 */
class PayrollSchemaInvariantsTest extends TestCase
{
    private function tables(): array
    {
        return [
            'salary_components', 'salary_structures', 'salary_structure_components',
            'employee_compensation_assignments', 'compensation_assignment_values',
            'payroll_periods', 'payroll_runs', 'payroll_run_results', 'payroll_run_result_lines',
            'payroll_adjustments', 'payroll_accounting_configurations', 'payroll_run_postings',
        ];
    }

    private function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }

    private function uuid(): string
    {
        return (string) new UuidV7;
    }

    private function makeSchool(): string
    {
        $id = $this->uuid();
        $this->admin()->table('schools')->insert([
            'id' => $id,
            'name' => 'Test School',
            'slug' => 'test-school-'.$id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeUser(): string
    {
        $id = $this->uuid();
        $this->admin()->table('users')->insert([
            'id' => $id,
            'name' => 'Test User',
            'email' => "user-{$id}@test.local",
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeEmployee(string $schoolId): string
    {
        $id = $this->uuid();
        $this->admin()->table('employees')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'employee_number' => 'E'.substr($id, -12),
            'full_name' => 'Test Employee',
            'record_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeEmploymentRecord(string $schoolId, string $employeeId): string
    {
        $id = $this->uuid();
        $this->admin()->table('employment_records')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'employee_id' => $employeeId,
            'employment_type' => 'permanent',
            'starts_on' => '2026-01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeStructure(string $schoolId, string $code, int $version, string $status = 'draft'): string
    {
        $id = $this->uuid();
        $this->admin()->table('salary_structures')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'code' => $code,
            'version' => $version,
            'name' => "{$code} v{$version}",
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeComponent(string $schoolId, string $type = 'earning'): string
    {
        $id = $this->uuid();
        $this->admin()->table('salary_components')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'code' => 'C'.substr($id, 0, 8),
            'name' => 'Test Component',
            'type' => $type,
            'currency' => 'INR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makePeriod(string $schoolId, string $month = '2026-09-01'): string
    {
        $id = $this->uuid();
        $start = Carbon::parse($month)->startOfMonth();
        $this->admin()->table('payroll_periods')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'period_month' => $start->toDateString(),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->endOfMonth()->toDateString(),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeRun(string $schoolId, string $periodId, string $preparerId, string $status = 'draft', string $runKind = 'regular', ?string $correctsId = null): string
    {
        $id = $this->uuid();
        $this->admin()->table('payroll_runs')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'payroll_period_id' => $periodId,
            'run_kind' => $runKind,
            'corrects_payroll_run_id' => $correctsId,
            'status' => $status,
            'prepared_by_user_id' => $preparerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function every_payroll_table_has_rls_enabled_and_forced(): void
    {
        foreach ($this->tables() as $table) {
            $row = $this->admin()->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "expected table {$table} to exist");
            $this->assertTrue((bool) $row->relrowsecurity, "{$table}: RLS not enabled");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table}: RLS not forced");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $schoolId = $this->makeSchool();
        $this->makeComponent($schoolId);

        $this->admin()->getPdo()->exec("set session role 'school_os_app'");
        $this->admin()->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $count = $this->admin()->table('salary_components')->count();
        $this->admin()->getPdo()->exec('reset role');

        $this->assertSame(0, $count);
    }

    #[Test]
    public function only_one_active_revision_per_code_is_allowed(): void
    {
        $schoolId = $this->makeSchool();
        $v1 = $this->makeStructure($schoolId, 'GRADE2', 1);
        $v2 = $this->makeStructure($schoolId, 'GRADE2', 2);

        $this->admin()->table('salary_structures')->where('id', $v1)->update(['status' => 'active']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->admin()->table('salary_structures')->where('id', $v2)->update(['status' => 'active']);
    }

    #[Test]
    public function structure_components_are_frozen_once_the_structure_is_active(): void
    {
        $schoolId = $this->makeSchool();
        $structureId = $this->makeStructure($schoolId, 'GRADE3', 1, 'active');
        $componentId = $this->makeComponent($schoolId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/is no longer draft/');

        $this->admin()->table('salary_structure_components')->insert([
            'id' => $this->uuid(),
            'school_id' => $schoolId,
            'salary_structure_id' => $structureId,
            'salary_component_id' => $componentId,
            'calculation_type' => 'fixed_amount',
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function compensation_assignments_reject_overlap_for_the_same_employment_record(): void
    {
        $schoolId = $this->makeSchool();
        $employeeId = $this->makeEmployee($schoolId);
        $employmentRecordId = $this->makeEmploymentRecord($schoolId, $employeeId);
        $structureId = $this->makeStructure($schoolId, 'GRADE4', 1, 'active');

        $this->admin()->table('employee_compensation_assignments')->insert([
            'id' => $this->uuid(),
            'school_id' => $schoolId,
            'employment_record_id' => $employmentRecordId,
            'salary_structure_id' => $structureId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/overlaps existing assignment/');

        $this->admin()->table('employee_compensation_assignments')->insert([
            'id' => $this->uuid(),
            'school_id' => $schoolId,
            'employment_record_id' => $employmentRecordId,
            'salary_structure_id' => $structureId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function compensation_assignments_for_different_employment_records_never_serialize_against_each_other(): void
    {
        $schoolId = $this->makeSchool();
        $er1 = $this->makeEmploymentRecord($schoolId, $this->makeEmployee($schoolId));
        $er2 = $this->makeEmploymentRecord($schoolId, $this->makeEmployee($schoolId));
        $structureId = $this->makeStructure($schoolId, 'GRADE5', 1, 'active');

        foreach ([$er1, $er2] as $employmentRecordId) {
            $this->admin()->table('employee_compensation_assignments')->insert([
                'id' => $this->uuid(),
                'school_id' => $schoolId,
                'employment_record_id' => $employmentRecordId,
                'salary_structure_id' => $structureId,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, $this->admin()->table('employee_compensation_assignments')
            ->whereIn('employment_record_id', [$er1, $er2])->count());
    }

    #[Test]
    public function a_run_cannot_be_approved_by_its_own_preparer(): void
    {
        $schoolId = $this->makeSchool();
        $preparerId = $this->makeUser();
        $periodId = $this->makePeriod($schoolId);
        $runId = $this->makeRun($schoolId, $periodId, $preparerId);

        $this->admin()->table('payroll_runs')->where('id', $runId)->update(['status' => 'calculated']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/payroll_runs_sod_check/');

        $this->admin()->table('payroll_runs')->where('id', $runId)->update([
            'status' => 'approved',
            'approved_by_user_id' => $preparerId,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function results_are_frozen_once_the_run_is_approved(): void
    {
        $schoolId = $this->makeSchool();
        $preparerId = $this->makeUser();
        $approverId = $this->makeUser();
        $employeeId = $this->makeEmployee($schoolId);
        $employmentRecordId = $this->makeEmploymentRecord($schoolId, $employeeId);
        $periodId = $this->makePeriod($schoolId);
        $runId = $this->makeRun($schoolId, $periodId, $preparerId);

        $this->admin()->table('payroll_runs')->where('id', $runId)->update(['status' => 'calculated']);
        $this->admin()->table('payroll_runs')->where('id', $runId)->update([
            'status' => 'approved',
            'approved_by_user_id' => $approverId,
            'approved_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/results are frozen/');

        $this->admin()->table('payroll_run_results')->insert([
            'id' => $this->uuid(),
            'school_id' => $schoolId,
            'payroll_run_id' => $runId,
            'employment_record_id' => $employmentRecordId,
            'employee_id' => $employeeId,
            'gross_amount' => 1000,
            'total_deductions' => 0,
            'net_amount' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_approved_run_cannot_move_backward_to_draft(): void
    {
        $schoolId = $this->makeSchool();
        $preparerId = $this->makeUser();
        $approverId = $this->makeUser();
        $periodId = $this->makePeriod($schoolId);
        $runId = $this->makeRun($schoolId, $periodId, $preparerId);

        $this->admin()->table('payroll_runs')->where('id', $runId)->update(['status' => 'calculated']);
        $this->admin()->table('payroll_runs')->where('id', $runId)->update([
            'status' => 'approved',
            'approved_by_user_id' => $approverId,
            'approved_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/invalid status transition/');

        $this->admin()->table('payroll_runs')->where('id', $runId)->update(['status' => 'draft']);
    }

    #[Test]
    public function a_correction_run_must_reference_a_posted_regular_run(): void
    {
        $schoolId = $this->makeSchool();
        $preparerId = $this->makeUser();
        $periodId = $this->makePeriod($schoolId);
        $originalId = $this->makeRun($schoolId, $periodId, $preparerId);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/must reference a posted run/');

        $this->makeRun($schoolId, $periodId, $preparerId, 'draft', 'correction', $originalId);
    }

    #[Test]
    public function composite_foreign_keys_reject_a_cross_school_reference(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $componentInB = $this->makeComponent($schoolB);

        $this->expectException(QueryException::class);

        $this->admin()->table('salary_structure_components')->insert([
            'id' => $this->uuid(),
            'school_id' => $schoolA,
            'salary_structure_id' => $this->uuid(),
            'salary_component_id' => $componentInB,
            'calculation_type' => 'fixed_amount',
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
