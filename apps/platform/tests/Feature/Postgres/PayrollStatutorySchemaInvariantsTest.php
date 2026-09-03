<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6C (ADR 0035) -- raw-DB-level proof of the statutory
 * schema's own invariants, mirroring `PayrollSchemaInvariantsTest`'s
 * exact single-connection (`pgsql_admin` throughout) discipline and
 * rationale (see that file's own docblock).
 */
class PayrollStatutorySchemaInvariantsTest extends TestCase
{
    private function schoolOwnedTables(): array
    {
        return [
            'payroll_salary_component_statutory_classifications',
            'employee_pf_status',
            'employee_esi_coverage',
            'employee_tax_profile',
            'employee_statutory_identifiers',
            'payroll_statutory_calculation_results',
            'payroll_statutory_accounting_configurations',
            'payroll_lwf_annual_charges',
        ];
    }

    private function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }

    #[Test]
    public function every_statutory_school_owned_table_has_rls_enabled_and_forced(): void
    {
        foreach ($this->schoolOwnedTables() as $table) {
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
    public function pf_rule_versions_are_platform_reference_data_with_no_rls(): void
    {
        foreach ([
            'payroll_pf_rule_versions', 'payroll_esi_rule_versions', 'payroll_lwf_rule_versions',
            'payroll_professional_tax_rule_versions', 'payroll_professional_tax_rule_slabs',
            'payroll_income_tax_rule_versions', 'payroll_income_tax_rule_slabs',
        ] as $table) {
            $row = $this->admin()->selectOne(
                'select relrowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );
            $this->assertNotNull($row, "expected table {$table} to exist");
            $this->assertFalse((bool) $row->relrowsecurity, "{$table}: platform reference data should never carry RLS, matching education_boards.");
        }
    }

    #[Test]
    public function lwf_annual_charge_has_a_structural_once_per_cycle_unique_constraint(): void
    {
        // Catalog-inspection proof (mirroring how earlier Payroll
        // checkpoints verify a constraint's existence directly)
        // rather than a live fixture: `unique(school_id,
        // employment_record_id, annual_cycle_year)` IS the mechanism
        // that makes a second same-cycle charge attempt structurally
        // impossible, independent of any application-level check.
        $row = $this->admin()->selectOne(
            "select indexdef from pg_indexes where tablename = 'payroll_lwf_annual_charges' and indexdef like '%UNIQUE%'",
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('school_id', $row->indexdef);
        $this->assertStringContainsString('employment_record_id', $row->indexdef);
        $this->assertStringContainsString('annual_cycle_year', $row->indexdef);
    }

    #[Test]
    public function statutory_calculation_results_freeze_trigger_exists(): void
    {
        $row = $this->admin()->selectOne(
            "select tgname from pg_trigger where tgname = 'trg_payroll_statutory_results_freeze'",
        );

        $this->assertNotNull($row, 'expected the freeze trigger on payroll_statutory_calculation_results to exist');
    }

    #[Test]
    public function statutory_identifiers_never_store_a_plaintext_column(): void
    {
        $columns = $this->admin()->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['employee_statutory_identifiers'],
        );
        $columnNames = array_map(fn ($c) => $c->column_name, $columns);

        $this->assertContains('encrypted_value', $columnNames);
        $this->assertContains('lookup_hash', $columnNames);
        foreach (['pan', 'uan', 'value', 'plaintext'] as $forbidden) {
            $this->assertNotContains($forbidden, $columnNames, "employee_statutory_identifiers must never carry a bare '{$forbidden}' column.");
        }
    }
}
