<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C/9.6F (ADR 0035 correction addendum §1.11) --
     * extends `payroll_accounting_configurations`' exact pattern
     * (composite FK to `ledger_accounts(id, school_id, currency)`,
     * one row per School, no name lookup, no hardcoded UUID) to the
     * additional statutory payable/expense accounts. A missing
     * mapping fails closed before any Finance side effect
     * (`PayrollStatutoryPostingService`, Checkpoint 9.6F) -- there is
     * deliberately no suspense-account fallback.
     *
     * Every composite foreign key below is given an EXPLICIT, short
     * name -- this table name plus several of the natural column
     * names (e.g. `employer_pf_contribution_expense_ledger_account_id`)
     * would otherwise produce an auto-generated constraint name well
     * past PostgreSQL's 63-byte identifier limit, risking silent
     * truncation collisions between two different composite FKs.
     */
    public function up(): void
    {
        Schema::create('payroll_statutory_accounting_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_pf_payable_ledger_account_id');
            $table->uuid('employer_eps_payable_ledger_account_id');
            $table->uuid('employer_epf_payable_ledger_account_id');
            $table->uuid('pf_admin_charge_payable_ledger_account_id');
            $table->uuid('edli_payable_ledger_account_id');
            $table->uuid('esi_payable_ledger_account_id');
            $table->uuid('tds_payable_ledger_account_id');
            $table->uuid('professional_tax_payable_ledger_account_id');
            $table->uuid('lwf_payable_ledger_account_id');
            $table->uuid('employer_pf_contribution_expense_ledger_account_id');
            $table->uuid('employer_esi_contribution_expense_ledger_account_id');
            $table->uuid('employer_lwf_contribution_expense_ledger_account_id');
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique('school_id');

            foreach ([
                'employee_pf_payable_ledger_account_id' => 'psac_employee_pf_payable_fk',
                'employer_eps_payable_ledger_account_id' => 'psac_employer_eps_payable_fk',
                'employer_epf_payable_ledger_account_id' => 'psac_employer_epf_payable_fk',
                'pf_admin_charge_payable_ledger_account_id' => 'psac_pf_admin_payable_fk',
                'edli_payable_ledger_account_id' => 'psac_edli_payable_fk',
                'esi_payable_ledger_account_id' => 'psac_esi_payable_fk',
                'tds_payable_ledger_account_id' => 'psac_tds_payable_fk',
                'professional_tax_payable_ledger_account_id' => 'psac_pt_payable_fk',
                'lwf_payable_ledger_account_id' => 'psac_lwf_payable_fk',
                'employer_pf_contribution_expense_ledger_account_id' => 'psac_employer_pf_expense_fk',
                'employer_esi_contribution_expense_ledger_account_id' => 'psac_employer_esi_expense_fk',
                'employer_lwf_contribution_expense_ledger_account_id' => 'psac_employer_lwf_expense_fk',
            ] as $column => $constraintName) {
                $table->foreign([$column, 'school_id', 'currency'], $constraintName)
                    ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                    ->restrictOnDelete();
            }
        });

        TenantRls::enable('payroll_statutory_accounting_configurations');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_statutory_accounting_configurations');
        Schema::dropIfExists('payroll_statutory_accounting_configurations');
    }
};
