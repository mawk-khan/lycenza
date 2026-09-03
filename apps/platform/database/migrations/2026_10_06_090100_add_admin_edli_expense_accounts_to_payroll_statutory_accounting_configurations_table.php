<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6F correction -- the original Checkpoint 9.6C
     * migration provisioned only ONE combined
     * `employer_pf_contribution_expense_ledger_account_id` column, but
     * ADR 0036 correction addendum §1.11 / the legal spec's §8 debit
     * account list names FIVE distinct employer-side expense accounts
     * (salary/gross expense already exists in
     * `payroll_accounting_configurations`; employer PF contribution
     * expense, PF ADMIN expense, EDLI expense, employer ESI expense,
     * and employer LWF expense are the four statutory-specific ones).
     * PF admin charge and EDLI were missing their own expense account
     * entirely -- `StatutoryPayrollPostingService` would otherwise
     * have had to debit the SAME account it credits as payable,
     * netting the entry to zero movement on that account instead of
     * recording a real expense. This is a genuinely additive
     * migration (never edits the original 9.6C migration file, which
     * is not itself published to `main` but is treated with the same
     * "never edit, always add a new migration" discipline as
     * published history).
     */
    public function up(): void
    {
        Schema::table('payroll_statutory_accounting_configurations', function (Blueprint $table) {
            $table->uuid('pf_admin_charge_expense_ledger_account_id')->nullable()->after('employer_pf_contribution_expense_ledger_account_id');
            $table->uuid('edli_expense_ledger_account_id')->nullable()->after('pf_admin_charge_expense_ledger_account_id');

            $table->foreign(['pf_admin_charge_expense_ledger_account_id', 'school_id', 'currency'], 'psac_pf_admin_expense_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
            $table->foreign(['edli_expense_ledger_account_id', 'school_id', 'currency'], 'psac_edli_expense_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_statutory_accounting_configurations', function (Blueprint $table) {
            $table->dropForeign('psac_pf_admin_expense_fk');
            $table->dropForeign('psac_edli_expense_fk');
            $table->dropColumn(['pf_admin_charge_expense_ledger_account_id', 'edli_expense_ledger_account_id']);
        });
    }
};
