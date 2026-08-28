<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0G.1 (ADR 0030 "Consequences", FINANCE.md "Balancing
     * enforcement"; renamed/expanded from
     * `add_balanced_journal_entry_enforcement` during the 0G.1 closure
     * review, then expanded again during the posting-txid hardening
     * pass -- both while still uncommitted, see docs/modules/FINANCE.md
     * "0G.1 as-built" for the full history). Adds THREE structural
     * invariants for `journal_entries`/`journal_lines`, all required
     * for ADR 0030's "posted accounting facts are append-only" to
     * actually hold, none expressible as a plain single-row CHECK:
     *
     * 1. POSTING_TXID IS DATABASE-AUTHORITATIVE, NEVER CALLER-SET
     *    (added by the posting-txid hardening pass). `journal_entries.
     *    posting_txid`'s `DEFAULT pg_current_xact_id()` (see that
     *    table's migration) only applies when a caller OMITS the
     *    column -- a raw INSERT can still explicitly supply an
     *    arbitrary `xid8` value, which would let a spoofed value defeat
     *    invariant 3 below (a spoofed `posting_txid` matching a FUTURE
     *    transaction's id would let that future transaction extend the
     *    entry's line set as if it were still the original posting).
     *    `finance_set_journal_entry_posting_txid()`, an UNCONDITIONAL
     *    `BEFORE INSERT` trigger on `journal_entries`, closes this:
     *    `NEW.posting_txid := pg_current_xact_id();` runs for every
     *    single insert with no `IF NEW.posting_txid IS NULL THEN`
     *    guard -- it always overwrites whatever the caller provided
     *    (or didn't), so the stored value is provably the real
     *    transaction id regardless of any caller-supplied column value.
     *    The column's own `DEFAULT` becomes redundant once this trigger
     *    exists (any value it fills in gets overwritten immediately
     *    after by this trigger) but is deliberately RETAINED anyway --
     *    see that table's migration for why: it is harmless, and
     *    removing it would be a schema change with no invariant
     *    benefit, only churn. THIS TRIGGER, not the column default, is
     *    the sole source of truth for `posting_txid`'s correctness.
     *
     * 2. BALANCED POSTING (unchanged from the original migration): a
     *    journal entry's lines must sum to zero (debits == credits)
     *    and there must be at least two of them. This is a multi-row,
     *    cross-table invariant, enforced via a `CONSTRAINT TRIGGER ...
     *    DEFERRABLE INITIALLY DEFERRED` on `journal_entries`, firing
     *    once per inserted HEADER row at COMMIT time (or an explicit
     *    `SET CONSTRAINTS ALL IMMEDIATE`) -- by which point the
     *    entry's lines have already been inserted in the same
     *    transaction. A trigger on `journal_lines` itself could never
     *    fire for the zero-lines case (no line row exists to fire
     *    it); firing per-`journal_entries`-row also naturally
     *    satisfies "multiple journal entries in one transaction are
     *    each independently checked" -- there is no global cross-entry
     *    SUM anywhere in this function.
     *
     * 3. POSTED LINE-SET IMMUTABILITY (added by the 0G.1 closure
     *    review, now spoof-proof because of invariant 1 above):
     *    append-only UPDATE/DELETE revocation on `journal_entries`/
     *    `journal_lines` (see those tables' own migrations) proves an
     *    existing ROW cannot be mutated or removed -- it does NOT
     *    prove a LATER transaction cannot INSERT an ADDITIONAL
     *    `journal_lines` row (even a balanced debit+credit pair)
     *    against an entry that a PRIOR, already-committed transaction
     *    posted. That would silently rewrite historical financial
     *    truth while leaving every existing row untouched. A `BEFORE
     *    INSERT` trigger on `journal_lines` compares the CURRENT
     *    transaction's id (`pg_current_xact_id()`) against the target
     *    `journal_entries` row's `posting_txid` (assigned
     *    authoritatively by invariant 1 above, at header insert time):
     *    equal means this insert is still part of the original atomic
     *    "header + lines" posting transaction (allowed); different
     *    means a separate, later transaction is attempting to extend
     *    an already-posted entry's line set (rejected). This is
     *    deliberately an IMMEDIATE (not deferred) `BEFORE INSERT`
     *    trigger -- it must run before every single line insert,
     *    including the legitimate ones during initial posting, to tell
     *    the two cases apart.
     *
     * NONE of the three functions use `SECURITY DEFINER` -- all run as
     * the invoking role (PostgreSQL's default, `SECURITY INVOKER`).
     * Invariant 1's trigger touches only the row already being
     * inserted (`NEW`), needing no cross-row access at all. Invariant
     * 2's `journal_lines` SELECT and invariant 3's `journal_entries`
     * SELECT both only ever read the SAME School's rows the entry/line
     * already belongs to, and RLS already permits the runtime
     * `school_os_app` role to read its own School's rows once
     * `App\Support\Tenancy\TenantContext` has set the
     * `app.current_school_id` session GUC (`TenantRls`) -- see section
     * 27's requirement to prefer a design that works naturally under
     * correctly established tenant context over an unsafe broad
     * `SECURITY DEFINER` grant. All three proven directly under the
     * real `school_os_app` runtime role, not merely the `pgsql_admin`
     * migration role -- see
     * tests/Feature/Postgres/FinanceRawIsolationTest.php and
     * tests/Feature/Finance/JournalEntryBalanceEnforcementTest.php/
     * JournalEntryLineSetImmutabilityTest.php/
     * JournalEntryPostingTxidHardeningTest.php.
     *
     * Invariant 3 relies on MVCC read-committed visibility for its
     * concurrency safety, not on any lock it takes itself: a
     * concurrent session attempting to insert a line against an entry
     * that is still uncommitted in ANOTHER session simply cannot see
     * that entry row at all yet (the composite foreign keys on
     * `journal_lines` already reject that case on their own), so
     * invariant 3 only ever needs to distinguish "my own still-open
     * transaction" from "some entry that already committed" -- proven
     * with two REAL, separate OS processes racing to extend the SAME
     * already-committed entry
     * (tests/Feature/Finance/JournalEntryLineSetImmutabilityConcurrencyTest.php).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE FUNCTION finance_set_journal_entry_posting_txid() RETURNS trigger AS $$
            BEGIN
                NEW.posting_txid := pg_current_xact_id();

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_entries_set_posting_txid
                BEFORE INSERT ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION finance_set_journal_entry_posting_txid();
        SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION finance_check_journal_entry_balanced() RETURNS trigger AS $$
            DECLARE
                v_line_count integer;
                v_debit_total numeric;
                v_credit_total numeric;
            BEGIN
                SELECT count(*), coalesce(sum(debit_amount), 0), coalesce(sum(credit_amount), 0)
                    INTO v_line_count, v_debit_total, v_credit_total
                    FROM journal_lines
                    WHERE journal_entry_id = NEW.id;

                IF v_line_count < 2 THEN
                    RAISE EXCEPTION
                        'journal_entry % must have at least two journal_lines rows (found %)',
                        NEW.id, v_line_count
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_debit_total <> v_credit_total THEN
                    RAISE EXCEPTION
                        'journal_entry % is unbalanced: total debits % != total credits %',
                        NEW.id, v_debit_total, v_credit_total
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_entries_balanced_check
                AFTER INSERT ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION finance_check_journal_entry_balanced();
        SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION finance_reject_post_commit_line_insert() RETURNS trigger AS $$
            DECLARE
                v_posting_txid xid8;
            BEGIN
                SELECT posting_txid INTO v_posting_txid
                    FROM journal_entries
                    WHERE id = NEW.journal_entry_id;

                IF v_posting_txid IS NULL THEN
                    RAISE EXCEPTION
                        'journal_entry % not found for journal_lines insert',
                        NEW.journal_entry_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF v_posting_txid <> pg_current_xact_id() THEN
                    RAISE EXCEPTION
                        'journal_entry % is already posted; its line set is immutable '
                        '(cannot insert an additional journal_lines row after the '
                        'original posting transaction has committed)',
                        NEW.journal_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_lines_reject_post_commit_insert
                BEFORE INSERT ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION finance_reject_post_commit_line_insert();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_reject_post_commit_insert ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS finance_reject_post_commit_line_insert()');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balanced_check ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS finance_check_journal_entry_balanced()');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_set_posting_txid ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS finance_set_journal_entry_posting_txid()');
    }
};
