<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Deduction accounting") -- the
     * Payroll -> Finance configuration seam. Exactly one row per
     * School (`UNIQUE(school_id)`) naming the two fixed accounts a
     * normal (non-statutory) payroll run posts to: salary expense
     * (debited with gross earnings) and salary payable (credited with
     * net pay). Per-deduction liability accounts live on
     * `salary_components.liability_ledger_account_id` instead of a
     * third column/table here -- a deduction's Finance destination is
     * a stable 1:1 property of the component itself, with no
     * evidenced need to vary by structure or context (a dedicated
     * mapping table would be speculative flexibility, rule 2).
     *
     * No account-name lookup, no hardcoded UUID: both columns are
     * composite FKs against Finance's own `ledger_accounts (id,
     * school_id, currency)` (rule 70) -- `ledger_accounts` itself has
     * no bare `(id, school_id)` unique index, only `(id, school_id,
     * currency)` (see that table's own migration docblock, "strong
     * pattern" also used by `journal_lines`), so `currency` here
     * mirrors that exact shape, not a Payroll-specific addition.
     * Same-School only, validated through Finance's read boundary at
     * configuration-save time (Checkpoint 9.5's
     * `PayrollAccountingConfigurationService`), not merely trusted at
     * posting time.
     */
    public function up(): void
    {
        Schema::create('payroll_accounting_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('salary_expense_ledger_account_id');
            $table->uuid('salary_payable_ledger_account_id');
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique('school_id');

            $table->foreign(['salary_expense_ledger_account_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
            $table->foreign(['salary_payable_ledger_account_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        TenantRls::enable('payroll_accounting_configurations');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_accounting_configurations');
        Schema::dropIfExists('payroll_accounting_configurations');
    }
};
