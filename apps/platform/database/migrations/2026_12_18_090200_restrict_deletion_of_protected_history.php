<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.1A (ADR 0076 §24, V-3): audit, accounting, settlement and grant
 * history must fail closed under deletion, never vanish through a cascade.
 *
 * The runtime role can delete neither a School nor a User (TenantRls::
 * revokeDelete, rule 86; E21.4), but these foreign keys were `ON DELETE
 * CASCADE`, so any privileged deletion of a School -- or of a User, through
 * `school_memberships` -> `membership_role_assignments` -- would silently
 * erase the evidence with it. Each is rebuilt under the SAME name as
 * `ON DELETE RESTRICT`:
 *
 * - A audit: `school_audit_events`;
 * - B accounting: the ledger (`ledger_accounts`, `journal_entries`,
 *   `journal_lines`), financial periods and their close/expiry snapshots,
 *   and the payroll ledger postings;
 * - C receivables and settlement: `charges`, `fee_adjustments`, `payments`,
 *   `payment_allocations`, `payment_receipts`, `payment_corrections`,
 *   `payment_correction_lines`, `payment_provider_events`;
 * - D grant history: `school_memberships` (School AND User) and
 *   `membership_role_assignments` (School AND membership) -- grants are
 *   revoked, never deleted (rule 92).
 *
 * Deliberately NOT changed: the ~190 other School cascades (operational and
 * configuration rows, and domain evidence governed by its own retention
 * contract and append-only grants), and the User cascades into credential
 * material (MFA factors, recovery codes, activation and recovery tokens).
 * Retention prunes delete these rows directly through the retention role
 * (E21), never through a School or User delete, so they are unaffected. No
 * purge path exists or is introduced. Existing rows are untouched; only the
 * delete action changes, so rollback restores CASCADE exactly.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> table, constraint, columns, parent, parent columns */
    private const KEYS = [
        // A audit
        ['school_audit_events', 'school_audit_events_school_id_foreign', 'school_id', 'schools', 'id'],
        // B accounting
        ['ledger_accounts', 'ledger_accounts_school_id_foreign', 'school_id', 'schools', 'id'],
        ['journal_entries', 'journal_entries_school_id_foreign', 'school_id', 'schools', 'id'],
        ['journal_lines', 'journal_lines_school_id_foreign', 'school_id', 'schools', 'id'],
        ['financial_periods', 'financial_periods_school_id_foreign', 'school_id', 'schools', 'id'],
        ['financial_period_account_balances', 'financial_period_account_balances_school_id_foreign', 'school_id', 'schools', 'id'],
        ['financial_period_charge_states', 'financial_period_charge_states_school_id_foreign', 'school_id', 'schools', 'id'],
        ['financial_period_expiries', 'financial_period_expiries_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payroll_run_postings', 'payroll_run_postings_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payroll_statutory_run_postings', 'payroll_statutory_run_postings_school_id_foreign', 'school_id', 'schools', 'id'],
        // C receivables and settlement
        ['charges', 'charges_school_id_foreign', 'school_id', 'schools', 'id'],
        ['fee_adjustments', 'fee_adjustments_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payments', 'payments_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payment_allocations', 'payment_allocations_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payment_receipts', 'payment_receipts_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payment_corrections', 'payment_corrections_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payment_correction_lines', 'payment_correction_lines_school_id_foreign', 'school_id', 'schools', 'id'],
        ['payment_provider_events', 'payment_provider_events_school_id_foreign', 'school_id', 'schools', 'id'],
        // D grant history
        ['school_memberships', 'school_memberships_school_id_foreign', 'school_id', 'schools', 'id'],
        ['school_memberships', 'school_memberships_user_id_foreign', 'user_id', 'users', 'id'],
        ['membership_role_assignments', 'membership_role_assignments_school_id_foreign', 'school_id', 'schools', 'id'],
        ['membership_role_assignments', 'membership_role_assignments_school_membership_id_school_id_fore', 'school_membership_id, school_id', 'school_memberships', 'id, school_id'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $this->rebuild(...[...$key, 'RESTRICT']);
        }
    }

    /** Restores the previous CASCADE definitions exactly. */
    public function down(): void
    {
        foreach (self::KEYS as $key) {
            $this->rebuild(...[...$key, 'CASCADE']);
        }
    }

    private function rebuild(string $table, string $constraint, string $columns, string $parent, string $parentColumns, string $onDelete): void
    {
        DB::statement(
            "ALTER TABLE {$table} DROP CONSTRAINT {$constraint}, "
            ."ADD CONSTRAINT {$constraint} FOREIGN KEY ({$columns}) REFERENCES {$parent} ({$parentColumns}) ON DELETE {$onDelete}"
        );
    }
};
