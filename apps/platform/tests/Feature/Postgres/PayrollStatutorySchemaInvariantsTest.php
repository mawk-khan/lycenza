<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6C (ADR 0036) -- raw-DB-level proof of the statutory
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
            'payroll_statutory_run_postings',
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
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['employee_statutory_identifiers'],
        );
        $columnNames = array_map(fn ($c) => $c->column_name, $columns);

        $this->assertContains('encrypted_value', $columnNames);
        $this->assertContains('lookup_hash', $columnNames);
        foreach (['pan', 'uan', 'value', 'plaintext'] as $forbidden) {
            $this->assertNotContains($forbidden, $columnNames, "employee_statutory_identifiers must never carry a bare '{$forbidden}' column.");
        }
    }

    /**
     * Checkpoint 9.6H -- `payroll_statutory_run_postings` (Checkpoint
     * 9.6F) is append-only: `school_os_app` (the runtime role every
     * request/queue connection actually uses) must hold no UPDATE/
     * DELETE privilege on it, mirroring `payroll_run_postings`'
     * identical proof.
     */
    #[Test]
    public function statutory_run_postings_is_append_only_for_the_runtime_role(): void
    {
        $privileges = $this->admin()->select(
            'select privilege_type from information_schema.role_table_grants '.
            "where table_name = 'payroll_statutory_run_postings' and grantee = 'school_os_app'",
        );
        $privilegeTypes = array_map(fn ($p) => $p->privilege_type, $privileges);

        $this->assertContains('SELECT', $privilegeTypes);
        $this->assertContains('INSERT', $privilegeTypes);
        $this->assertNotContains('UPDATE', $privilegeTypes, 'payroll_statutory_run_postings must be append-only -- school_os_app must never hold UPDATE.');
        $this->assertNotContains('DELETE', $privilegeTypes, 'payroll_statutory_run_postings must be append-only -- school_os_app must never hold DELETE.');
    }

    #[Test]
    public function statutory_run_postings_has_the_one_original_per_run_structural_guarantee(): void
    {
        $row = $this->admin()->selectOne(
            "select indexdef from pg_indexes where indexname = 'payroll_statutory_run_postings_one_original_per_run'",
        );

        $this->assertNotNull($row, 'expected the one-original-per-run partial unique index to exist');
        $this->assertStringContainsString('UNIQUE', $row->indexdef);
    }

    /**
     * Checkpoint 9.6F correction -- proves the PF-admin-charge and
     * EDLI expense accounts (missing in the original 9.6C migration,
     * added by a dedicated additive migration) actually exist as real
     * composite-FK-backed columns, not just fillable array entries.
     */
    #[Test]
    public function statutory_accounting_configuration_has_dedicated_pf_admin_and_edli_expense_accounts(): void
    {
        $columns = $this->admin()->select(
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['payroll_statutory_accounting_configurations'],
        );
        $columnNames = array_map(fn ($c) => $c->column_name, $columns);

        $this->assertContains('pf_admin_charge_expense_ledger_account_id', $columnNames);
        $this->assertContains('edli_expense_ledger_account_id', $columnNames);

        foreach (['psac_pf_admin_expense_fk', 'psac_edli_expense_fk'] as $constraintName) {
            $exists = $this->admin()->selectOne(
                'select conname from pg_constraint where conname = ?',
                [$constraintName],
            );
            $this->assertNotNull($exists, "expected composite FK constraint {$constraintName} to exist");
        }
    }
}
