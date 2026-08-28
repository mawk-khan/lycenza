<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.1 (ADR 0030): one authoritative posted financial fact's
     * header. No `status`/draft column -- ADR 0030's Decision #1:
     * existence means posted, there is no draft ledger-posting state
     * in the ledger core. No `updated_at` -- a posted journal entry is
     * never mutated after insert (see the append-only migration below
     * for the database-privilege-level enforcement); adding a
     * timestamp column with no legitimate write path to update it
     * would misrepresent the immutability guarantee to a future reader
     * of the schema (rule 33).
     *
     * Deliberately does NOT reference any charge/invoice/payment/
     * student/guardian/employee/applicant row -- ADR 0030's core
     * ledger deals only in accounts, per docs/modules/FINANCE.md
     * "Core entities for 0G.1"; those relationships are 0G.4/0G.5
     * concerns.
     *
     * Reversal linkage (ADR 0030 "Reversal linkage direction"): the
     * REVERSING entry points at the original via
     * `reversal_of_journal_entry_id` -- the original row is never
     * written to again. The composite self-referential foreign key
     * `(reversal_of_journal_entry_id, school_id, currency) references
     * (id, school_id, currency)` structurally proves a reversal is
     * always same-School AND same-currency, not merely an application
     * check. The partial unique index on `reversal_of_journal_entry_id`
     * (only where NOT NULL) is what makes "one full reversal only" a
     * database constraint, not a check-then-insert race (mirrors
     * `sgal_one_active_per_membership`'s partial-unique-index pattern
     * already established elsewhere in this repository) -- a second
     * concurrent attempt to reverse the same original fails the unique
     * constraint, it does not silently succeed. The plain CHECK
     * rejects self-reversal (`id = reversal_of_journal_entry_id`).
     *
     * `unique(['id', 'school_id', 'currency'])` is the same "tenant +
     * currency" composite-FK-target pattern used by ledger_accounts --
     * both this table's own self-referential reversal FK AND
     * journal_lines' FK reference it.
     *
     * Currency scope (0G.1 correction): see ledger_accounts' migration
     * docblock -- FINANCE.md's own text ("today: INR only") is Phase
     * 0G is INR-only, globally, not merely single-currency-per-School;
     * `journal_entries_currency_inr_only_check` enforces `currency =
     * 'INR'` directly. The column stays explicit.
     *
     * `posting_txid` (0G.1 correction, section 1 of the closure
     * review, hardened further by the posting-txid hardening pass):
     * ADR 0030 requires a posted JournalEntry's LINE SET, not only the
     * header/line ROWS themselves, to be immutable after commit --
     * append-only UPDATE/DELETE revocation alone does not prevent a
     * LATER transaction from INSERTing an additional journal_lines row
     * (even a balanced pair) against an already-committed entry.
     * `posting_txid` records, permanently, the exact transaction that
     * created this header row -- internal persistence metadata, never
     * a Finance business attribute, and never exposed to any future
     * API/DTO.
     *
     * `xid8` (not the classic 32-bit `xid`/`txid_current()`) is used
     * specifically because it is 64-bit and does not wrap around,
     * appropriate for a financial ledger meant to remain correct
     * indefinitely.
     *
     * IMPORTANT: the `DEFAULT pg_current_xact_id()` below is NOT what
     * makes this column trustworthy -- a PostgreSQL `DEFAULT` only
     * applies when a caller OMITS the column; a raw INSERT can still
     * explicitly supply an arbitrary `xid8` value, which would let a
     * spoofed value defeat the line-set-immutability trigger below
     * (which trusts this column completely). The actual, sole source
     * of truth is `finance_set_journal_entry_posting_txid()` (see the
     * posting-invariants migration), an UNCONDITIONAL `BEFORE INSERT`
     * trigger on this table that overwrites `posting_txid` on every
     * single insert regardless of any caller-supplied value -- proven
     * directly by a raw-SQL spoof attempt
     * (tests/Feature/Finance/JournalEntryPostingTxidHardeningTest.php).
     * The `DEFAULT` here is retained only as harmless, redundant
     * convenience (whatever it fills in gets overwritten immediately
     * after by the trigger) -- removing it would be schema churn with
     * no invariant benefit, so it stays, but it must never be read as
     * "the enforcement".
     *
     * `finance_reject_post_commit_line_insert()` (see the posting-
     * invariants migration) compares a candidate journal_lines
     * insert's `pg_current_xact_id()` against its target entry's
     * (now-guaranteed-authoritative) stored `posting_txid`: equal
     * means "still inside the original posting transaction"
     * (legitimate), different means "a later transaction trying to
     * extend historical financial truth" (rejected). This column
     * requires no application code to set it -- it is entirely
     * database-computed and is not in any model's `$fillable`.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('description');
            $table->timestamp('posted_at')->useCurrent();
            $table->uuid('reversal_of_journal_entry_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id', 'currency']);
            $table->index('school_id');
            $table->index(['school_id', 'posted_at']);
        });

        // Records the exact transaction that created this header row
        // -- see this migration's docblock ("posting_txid"). The
        // DEFAULT here is redundant convenience only; the actual
        // enforcement is the unconditional BEFORE INSERT trigger added
        // in the posting-invariants migration
        // (finance_set_journal_entry_posting_txid()), which overwrites
        // this value on every insert regardless of what a caller
        // supplies. No application code sets this column.
        DB::statement(
            'ALTER TABLE journal_entries ADD COLUMN posting_txid xid8 NOT NULL DEFAULT pg_current_xact_id()'
        );

        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_no_self_reversal_check '.
            'CHECK (reversal_of_journal_entry_id IS NULL OR reversal_of_journal_entry_id <> id)'
        );

        // Partial unique index: at most one journal_entries row may
        // name a given original as the entry it reverses (ADR 0030's
        // "one full reversal only" -- double-reversal prevention is
        // structural, not 0G.2 service logic).
        DB::statement(
            'CREATE UNIQUE INDEX journal_entries_reversal_of_unique '.
            'ON journal_entries (reversal_of_journal_entry_id) '.
            'WHERE reversal_of_journal_entry_id IS NOT NULL'
        );

        // Self-referential composite FK: a reversal must reference an
        // existing entry in the SAME School and the SAME currency.
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reversal_of_fk '.
            'FOREIGN KEY (reversal_of_journal_entry_id, school_id, currency) '.
            'REFERENCES journal_entries (id, school_id, currency)'
        );

        TenantRls::enable('journal_entries');
        TenantRls::makeAppendOnly('journal_entries');
    }

    public function down(): void
    {
        TenantRls::disable('journal_entries');
        Schema::dropIfExists('journal_entries');
    }
};
