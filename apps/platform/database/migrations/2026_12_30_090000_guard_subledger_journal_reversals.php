<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8-P1 (ADR 0076 §57, closes ORG.8 G-1): a journal entry whose lifecycle
 * another domain owns is never reversed except through that domain. Before
 * this, only a Payment's settlement entry was protected
 * (`journal_entries_payment_reversal_guard`, kept unchanged); the generic
 * ledger reversal could reverse a Charge's, fee adjustment's, Payment
 * correction's or payroll posting's entry and leave the owning record live
 * against a reversed entry.
 *
 * Ownership is structural: an entry is owned when an owner column names it
 * (every creator writes its entry and its owner row in one transaction, and
 * none of those columns ever changes afterwards), never inferred from a
 * description. `journal_entry_subledger_owner()` is the one classification:
 *
 *   reversed only through the owner     charge, fee_adjustment, payroll_posting,
 *   (the owner links the reversal)      payroll_statutory_posting
 *   never reversed                      payment, payment_correction,
 *                                       charge_cancellation, fee_adjustment_cancellation,
 *                                       payroll_posting_reversal, payroll_statutory_posting_reversal
 *   NULL                                ledger-native (a manual posting, or a reversal the
 *                                       generic ledger itself created) -- reversible as before
 *
 * The never-reversed kinds are tested first, so an entry that any of them
 * names stays terminal even if a (raw-SQL) row elsewhere also names it.
 *
 * Two backstops on journal_entries, for every role:
 *   - BEFORE INSERT: a reversal of a never-reversed entry is refused at once;
 *   - DEFERRED (at COMMIT): a reversal of an owner-reversed entry must, by
 *     then, be linked by its owner (the Charge's / adjustment's
 *     cancellation entry, or a payroll reversal posting). The owners'
 *     canonical reversals link it in the same transaction; anything else
 *     -- the generic ledger, raw SQL -- is rolled back.
 *
 * SECURITY INVOKER throughout. The immediate guard runs under the
 * inserter's own School context (RLS refuses the insert otherwise). The
 * deferred check pins the context to the row's own School for its reads
 * only (a cleared context at COMMIT must not make the owner invisible, i.e.
 * fail open) and restores it; it reads nothing beyond that School, whose
 * row the inserter was already allowed to write.
 *
 * Rolling back drops only what this migration adds; the Payment guard and
 * every journal row stay as they are. No data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.journal_entry_subledger_owner(p_entry uuid, p_school uuid) RETURNS text
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                -- Never-reversed kinds first: an entry any of them names is terminal, whatever else names it.
                IF EXISTS (SELECT 1 FROM public.payments o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'payment';
                END IF;
                IF EXISTS (SELECT 1 FROM public.payment_corrections o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'payment_correction';
                END IF;
                IF EXISTS (SELECT 1 FROM public.charges o WHERE o.school_id = p_school AND o.cancellation_journal_entry_id = p_entry) THEN
                    RETURN 'charge_cancellation';
                END IF;
                IF EXISTS (SELECT 1 FROM public.fee_adjustments o WHERE o.school_id = p_school AND o.cancellation_journal_entry_id = p_entry) THEN
                    RETURN 'fee_adjustment_cancellation';
                END IF;
                IF EXISTS (SELECT 1 FROM public.payroll_run_postings o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry AND o.posting_kind = 'reversal') THEN
                    RETURN 'payroll_posting_reversal';
                END IF;
                IF EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry AND o.posting_kind = 'reversal') THEN
                    RETURN 'payroll_statutory_posting_reversal';
                END IF;
                -- Then the originals each owner reverses itself.
                IF EXISTS (SELECT 1 FROM public.charges o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'charge';
                END IF;
                IF EXISTS (SELECT 1 FROM public.fee_adjustments o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'fee_adjustment';
                END IF;
                IF EXISTS (SELECT 1 FROM public.payroll_run_postings o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'payroll_posting';
                END IF;
                IF EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings o WHERE o.school_id = p_school AND o.journal_entry_id = p_entry) THEN
                    RETURN 'payroll_statutory_posting';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE FUNCTION public.journal_entries_refuse_terminal_subledger_reversal() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                owner text := public.journal_entry_subledger_owner(NEW.reversal_of_journal_entry_id, NEW.school_id);
            BEGIN
                IF owner IS NOT NULL AND owner NOT IN ('charge', 'fee_adjustment', 'payroll_posting', 'payroll_statutory_posting') THEN
                    RAISE EXCEPTION 'journal_entries_subledger_reversal_guard: journal entry % is a % entry and is never reversed', NEW.reversal_of_journal_entry_id, owner
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_entries_subledger_reversal_guard
                BEFORE INSERT ON journal_entries
                FOR EACH ROW WHEN (NEW.reversal_of_journal_entry_id IS NOT NULL)
                EXECUTE FUNCTION public.journal_entries_refuse_terminal_subledger_reversal();

            CREATE FUNCTION public.journal_entries_require_owner_linked_reversal() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                prior text := current_setting('app.current_school_id', true);
                owner text;
                linked boolean := false;
            BEGIN
                PERFORM set_config('app.current_school_id', NEW.school_id::text, true);
                owner := public.journal_entry_subledger_owner(NEW.reversal_of_journal_entry_id, NEW.school_id);

                IF owner = 'charge' THEN
                    linked := EXISTS (SELECT 1 FROM public.charges o WHERE o.school_id = NEW.school_id
                        AND o.journal_entry_id = NEW.reversal_of_journal_entry_id AND o.cancellation_journal_entry_id = NEW.id);
                ELSIF owner = 'fee_adjustment' THEN
                    linked := EXISTS (SELECT 1 FROM public.fee_adjustments o WHERE o.school_id = NEW.school_id
                        AND o.journal_entry_id = NEW.reversal_of_journal_entry_id AND o.cancellation_journal_entry_id = NEW.id);
                ELSIF owner = 'payroll_posting' THEN
                    linked := EXISTS (SELECT 1 FROM public.payroll_run_postings r JOIN public.payroll_run_postings o ON o.id = r.reversal_of_payroll_run_posting_id
                        WHERE r.school_id = NEW.school_id AND r.journal_entry_id = NEW.id AND o.journal_entry_id = NEW.reversal_of_journal_entry_id);
                ELSIF owner = 'payroll_statutory_posting' THEN
                    linked := EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings r JOIN public.payroll_statutory_run_postings o ON o.id = r.reversal_of_posting_id
                        WHERE r.school_id = NEW.school_id AND r.journal_entry_id = NEW.id AND o.journal_entry_id = NEW.reversal_of_journal_entry_id);
                END IF;

                PERFORM set_config('app.current_school_id', coalesce(prior, ''), true);

                IF owner IS NOT NULL AND NOT linked THEN
                    RAISE EXCEPTION 'journal_entries_subledger_reversal_guard: journal entry % is a % entry and is reversed only through its owner', NEW.reversal_of_journal_entry_id, owner
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER journal_entries_subledger_reversal_owner_check
                AFTER INSERT ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW WHEN (NEW.reversal_of_journal_entry_id IS NOT NULL)
                EXECUTE FUNCTION public.journal_entries_require_owner_linked_reversal();

            REVOKE ALL ON FUNCTION public.journal_entry_subledger_owner(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.journal_entries_refuse_terminal_subledger_reversal() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.journal_entries_require_owner_linked_reversal() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.journal_entry_subledger_owner(uuid, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_entries_subledger_reversal_owner_check ON journal_entries;
            DROP TRIGGER IF EXISTS journal_entries_subledger_reversal_guard ON journal_entries;
            DROP FUNCTION IF EXISTS public.journal_entries_require_owner_linked_reversal();
            DROP FUNCTION IF EXISTS public.journal_entries_refuse_terminal_subledger_reversal();
            DROP FUNCTION IF EXISTS public.journal_entry_subledger_owner(uuid, uuid);
            SQL);
    }
};
