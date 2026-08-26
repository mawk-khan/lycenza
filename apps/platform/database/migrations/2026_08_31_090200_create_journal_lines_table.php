<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.1 (ADR 0030): one debit or credit posting within one
     * journal_entries row, against exactly one ledger_accounts row.
     * No `updated_at` -- same append-only rationale as journal_entries
     * (see that migration's docblock and the append-only migration
     * below).
     *
     * `debit_amount`/`credit_amount` (NUMERIC(14,2), both nullable):
     * ADR 0030's chosen representation is "never both, never a signed
     * single column" -- the CHECK constraint below requires EXACTLY
     * one of the two to be non-null and strictly positive. This
     * removes sign-convention ambiguity from every future reader of
     * this table (a $100 debit is `debit_amount = 100.00,
     * credit_amount = NULL`, never `amount = 100.00, side = 'debit'`
     * and never `amount = -100.00`).
     *
     * NUMERIC(14,2): FINANCE.md "Money / Database representation"
     * leaves the exact precision/scale to 0G.1, scoped to "today: INR
     * only, 2 decimal places / paise". Scale 2 matches that directly.
     * Precision 14 (12 integer digits + 2 fractional = up to
     * 999,999,999,999.99) is chosen with deliberate headroom over any
     * plausible single ledger posting or aggregate this repository's
     * domain will produce (a whole School's total annual fee revenue,
     * or a Payroll run's total payable, in INR) while still being an
     * explicit, bounded limit rather than an unconstrained NUMERIC --
     * see docs/modules/FINANCE.md's 0G.1 as-built section for the
     * full rationale. `ledger_accounts` carries no monetary column, so
     * this table and journal balances are the only place this
     * precision/scale decision applies in 0G.1.
     *
     * Composite foreign keys against `(id, school_id, currency)` on
     * BOTH parent tables (journal_entries, ledger_accounts) --
     * docs/modules/FINANCE.md's "strong pattern": the database itself
     * proves a journal line's School and currency match both its
     * parent entry AND its ledger account, not just application
     * validation. This transitively also proves the referenced
     * ledger_account's currency equals the referenced journal_entry's
     * currency (both must equal this row's own `currency`), which is
     * exactly what "journal lines cannot silently mix currencies with
     * their entry/account" (FINANCE.md) requires.
     *
     * No `subject_type`/`subject_id`, no `student_id`/`payment_id`/
     * anything else naming a financial subject or payer -- ADR 0030 /
     * FINANCE.md "Core entities for 0G.1": the ledger core deals only
     * in accounts.
     */
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('journal_entry_id');
            $table->uuid('ledger_account_id');
            $table->char('currency', 3);
            $table->decimal('debit_amount', 14, 2)->nullable();
            $table->decimal('credit_amount', 14, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('school_id');
            $table->index(['school_id', 'journal_entry_id']);
            $table->index(['school_id', 'ledger_account_id']);

            $table->foreign(['journal_entry_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')
                ->cascadeOnDelete();

            $table->foreign(['ledger_account_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_exactly_one_side_check '.
            'CHECK ('.
            '(debit_amount IS NOT NULL AND debit_amount > 0 AND credit_amount IS NULL) OR '.
            '(credit_amount IS NOT NULL AND credit_amount > 0 AND debit_amount IS NULL)'.
            ')'
        );

        TenantRls::enable('journal_lines');
        TenantRls::makeAppendOnly('journal_lines');
    }

    public function down(): void
    {
        TenantRls::disable('journal_lines');
        Schema::dropIfExists('journal_lines');
    }
};
