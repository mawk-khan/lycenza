<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E21.3A2 (ADR 0064 §14-§18, E21-D8 project-adopted, pending legal
 * ratification): the narrow privileged path that expires historical Finance
 * detail once its financial period closed more than 8 calendar years ago.
 *
 * - `financial_period_expiries`: append-only lineage of every expired unit
 *   (the latest period its entries belonged to, when, and counts; never an
 *   amount or a deleted row's content). Written only by the function below;
 *   the runtime role can read it, nothing else. The balance verifier uses
 *   it as the anchor: detail at or before an expired period may be gone,
 *   so all-history readings start from that period's baseline.
 * - `retention_expire_finance_unit(school, charge ids, standalone journal
 *   entry ids, record id, dry run)`: ONE fixed purpose, a settled Finance
 *   unit. SECURITY DEFINER, `search_path` pinned, every table qualified,
 *   EXECUTE for the runtime role only, called only through
 *   `RetentionExpiry`. The DATABASE verifies the unit on its own; the
 *   caller's selection is never trusted:
 *     - the School is the caller's tenant context;
 *     - the unit is closed under every reference: all allocations of its
 *       Payments, both sides of every late fee, every reversal link, and no
 *       remaining charge, adjustment, Payment, payroll posting or canteen
 *       order refers to anything in it;
 *     - every charge is cancelled or fully settled (outstanding exactly 0);
 *     - every journal entry has a period that is CLOSED, closed at least
 *       8 calendar years ago (the D8 floor; a longer configured period is
 *       the caller's choice), and the School's latest closed period has
 *       its account baseline;
 *     - it takes the School's period-maintenance lock (the close takes it
 *       too), then locks the unit's charges and entries, so a concurrent
 *       close, payment, reversal or adjustment void serializes with it.
 *   It then deletes, in dependency order: receipts, allocations, late-fee
 *   run items and assessments, fee-assessment run items, adjustments, fee
 *   assessments, targeted concessions, the charges' carried states, the
 *   charges, Payments and their provider events, and the journal entries
 *   (lines cascade). Account baselines, periods and receipt counters are
 *   never touched.
 * - Three guards that allowed only cascaded deletes (receipts, fee and
 *   late-fee run items) also allow a delete inside this function: the
 *   transaction-local `app.finance_retention_unit` flag AND the table
 *   owner's privileges, which the runtime role never has.
 *
 * Rollback removes the mechanism only, never pretends to restore evidence:
 * it refuses once any unit has been expired (the lineage must stay with the
 * baselines that now carry that history).
 */
return new class extends Migration
{
    private const GUARDED = ['fees_guard_fee_assessment_run_item', 'payments_guard_late_fee_run_item', 'payments_validate_payment_receipt'];

    private const ORIGINAL = 'IF pg_trigger_depth() > 1 THEN';

    private const PATCHED = 'IF pg_trigger_depth() > 1 OR public.finance_retention_delete_allowed() THEN';

    public function up(): void
    {
        Schema::create('financial_period_expiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('financial_period_id');
            $table->timestamp('expired_at');
            $table->integer('charges');
            $table->integer('payments');
            $table->integer('journal_entries');

            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index(['school_id', 'financial_period_id']);
        });
        DB::statement('ALTER TABLE financial_period_expiries ADD CONSTRAINT fpe_period_fk
            FOREIGN KEY (financial_period_id, school_id) REFERENCES financial_periods (id, school_id) ON DELETE RESTRICT');
        TenantRls::enable('financial_period_expiries');
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON financial_period_expiries FROM school_os_app');

        DB::unprepared(<<<'SQL'
            -- True only inside retention_expire_finance_unit: its transaction-local
            -- flag, AND the table owner's privileges (the definer's), which the
            -- runtime role never has.
            CREATE FUNCTION finance_retention_delete_allowed() RETURNS boolean
                LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp AS $$
                SELECT coalesce(current_setting('app.finance_retention_unit', true), '') = 'on'
                   AND pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = 'public.financial_periods'::regclass), 'MEMBER')
            $$;

            CREATE FUNCTION retention_expire_finance_unit(p_school_id uuid, p_charge_ids uuid[], p_entry_ids uuid[], p_record_id uuid, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_charges uuid[] := coalesce(p_charge_ids, '{}');
                v_payments uuid[];
                v_events uuid[];
                v_entries uuid[];
                v_period uuid;
                v_latest uuid;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                IF cardinality(v_charges) + cardinality(coalesce(p_entry_ids, '{}')) = 0 THEN
                    RETURN 0;
                END IF;
                IF cardinality(v_charges) > 500 OR cardinality(coalesce(p_entry_ids, '{}')) > 2000 THEN
                    RAISE EXCEPTION 'retention: the unit is too large (retention_finance_unit)';
                END IF;

                -- One maintenance lock order: the School's period-maintenance lock
                -- (the close takes it first too), then charges, then entries.
                PERFORM pg_advisory_xact_lock(hashtextextended('finance.period_maintenance:' || p_school_id::text, 0));
                PERFORM 1 FROM public.charges WHERE school_id = p_school_id AND id = ANY (v_charges) ORDER BY id FOR UPDATE;
                IF (SELECT count(*) FROM public.charges WHERE school_id = p_school_id AND id = ANY (v_charges)) <> cardinality(v_charges) THEN
                    RAISE EXCEPTION 'retention: unknown charge in the unit (retention_finance_unit)';
                END IF;

                SELECT coalesce(array_agg(DISTINCT payment_id), '{}') INTO v_payments
                  FROM public.payment_allocations WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                SELECT coalesce(array_agg(DISTINCT provider_event_id) FILTER (WHERE provider_event_id IS NOT NULL), '{}') INTO v_events
                  FROM public.payments WHERE school_id = p_school_id AND id = ANY (v_payments);

                SELECT coalesce(array_agg(DISTINCT e), '{}') INTO v_entries FROM (
                    SELECT unnest(coalesce(p_entry_ids, '{}')) AS e
                    UNION SELECT journal_entry_id FROM public.charges WHERE school_id = p_school_id AND id = ANY (v_charges)
                    UNION SELECT cancellation_journal_entry_id FROM public.charges WHERE school_id = p_school_id AND id = ANY (v_charges)
                    UNION SELECT journal_entry_id FROM public.fee_adjustments WHERE school_id = p_school_id AND charge_id = ANY (v_charges)
                    UNION SELECT cancellation_journal_entry_id FROM public.fee_adjustments WHERE school_id = p_school_id AND charge_id = ANY (v_charges)
                    UNION SELECT journal_entry_id FROM public.payments WHERE school_id = p_school_id AND id = ANY (v_payments)
                ) s WHERE e IS NOT NULL;
                PERFORM 1 FROM public.journal_entries WHERE school_id = p_school_id AND id = ANY (v_entries) ORDER BY id FOR UPDATE;

                -- Closed under every reference.
                IF EXISTS (SELECT 1 FROM public.canteen_orders WHERE school_id = p_school_id AND charge_id = ANY (v_charges))
                   OR EXISTS (SELECT 1 FROM public.payment_allocations WHERE school_id = p_school_id AND payment_id = ANY (v_payments) AND NOT (charge_id = ANY (v_charges)))
                   OR EXISTS (SELECT 1 FROM public.late_fee_assessments WHERE school_id = p_school_id
                               AND (charge_id = ANY (v_charges)) <> (source_charge_id = ANY (v_charges)))
                   OR EXISTS (SELECT 1 FROM public.fee_adjustments a JOIN public.fee_concessions c ON c.id = a.fee_concession_id AND c.school_id = a.school_id
                               WHERE a.school_id = p_school_id AND c.charge_id = ANY (v_charges) AND NOT (a.charge_id = ANY (v_charges)))
                   OR EXISTS (SELECT 1 FROM public.journal_entries WHERE school_id = p_school_id AND reversal_of_journal_entry_id = ANY (v_entries) AND NOT (id = ANY (v_entries)))
                   OR EXISTS (SELECT 1 FROM public.journal_entries WHERE school_id = p_school_id AND id = ANY (v_entries)
                               AND reversal_of_journal_entry_id IS NOT NULL AND NOT (reversal_of_journal_entry_id = ANY (v_entries)))
                   OR EXISTS (SELECT 1 FROM public.charges WHERE school_id = p_school_id AND NOT (id = ANY (v_charges))
                               AND (journal_entry_id = ANY (v_entries) OR cancellation_journal_entry_id = ANY (v_entries)))
                   OR EXISTS (SELECT 1 FROM public.fee_adjustments WHERE school_id = p_school_id AND NOT (charge_id = ANY (v_charges))
                               AND (journal_entry_id = ANY (v_entries) OR cancellation_journal_entry_id = ANY (v_entries)))
                   OR EXISTS (SELECT 1 FROM public.payments WHERE school_id = p_school_id AND NOT (id = ANY (v_payments)) AND journal_entry_id = ANY (v_entries))
                   OR EXISTS (SELECT 1 FROM public.payroll_run_postings WHERE school_id = p_school_id AND journal_entry_id = ANY (v_entries))
                   OR EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND journal_entry_id = ANY (v_entries)) THEN
                    RAISE EXCEPTION 'retention: the unit is still referenced (retention_finance_dependency)';
                END IF;

                -- Settled: cancelled, or nothing outstanding.
                IF EXISTS (SELECT 1 FROM public.charges c WHERE c.school_id = p_school_id AND c.id = ANY (v_charges) AND c.cancelled_at IS NULL
                            AND c.amount
                                - coalesce((SELECT sum(a.amount) FROM public.payment_allocations a WHERE a.school_id = c.school_id AND a.charge_id = c.id), 0)
                                - coalesce((SELECT sum(f.amount) FROM public.fee_adjustments f WHERE f.school_id = c.school_id AND f.charge_id = c.id AND f.cancelled_at IS NULL), 0) <> 0) THEN
                    RAISE EXCEPTION 'retention: a charge still has an outstanding amount (retention_finance_open_charge)';
                END IF;

                -- The D8 floor: every entry is in a CLOSED period, closed >= 8 calendar years ago.
                IF EXISTS (SELECT 1 FROM unnest(v_entries) x(id)
                             LEFT JOIN public.journal_entries j ON j.id = x.id AND j.school_id = p_school_id
                             LEFT JOIN public.financial_periods p ON p.id = j.financial_period_id AND p.school_id = p_school_id
                            WHERE j.id IS NULL OR p.id IS NULL OR p.status <> 'closed'
                               OR p.closed_at > (now() AT TIME ZONE 'UTC') - interval '8 years') THEN
                    RAISE EXCEPTION 'retention: an entry is not in a period closed 8 years ago (retention_floor)';
                END IF;
                SELECT id INTO v_latest FROM public.financial_periods WHERE school_id = p_school_id AND status = 'closed' ORDER BY starts_on DESC LIMIT 1;
                IF NOT EXISTS (SELECT 1 FROM public.financial_period_account_balances WHERE school_id = p_school_id AND financial_period_id = v_latest) THEN
                    RAISE EXCEPTION 'retention: the latest closed period has no account baseline (retention_finance_baseline)';
                END IF;

                IF p_dry_run THEN
                    RETURN cardinality(v_entries);
                END IF;

                PERFORM set_config('app.finance_retention_unit', 'on', true);
                DELETE FROM public.payment_receipts WHERE school_id = p_school_id AND payment_id = ANY (v_payments);
                DELETE FROM public.payment_allocations WHERE school_id = p_school_id AND payment_id = ANY (v_payments);
                DELETE FROM public.late_fee_run_items WHERE school_id = p_school_id
                   AND (source_charge_id = ANY (v_charges)
                        OR late_fee_assessment_id IN (SELECT id FROM public.late_fee_assessments WHERE school_id = p_school_id AND charge_id = ANY (v_charges)));
                DELETE FROM public.late_fee_assessments WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                DELETE FROM public.fee_assessment_run_items WHERE school_id = p_school_id
                   AND fee_assessment_id IN (SELECT id FROM public.fee_assessments WHERE school_id = p_school_id AND charge_id = ANY (v_charges));
                DELETE FROM public.fee_adjustments WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                DELETE FROM public.fee_assessments WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                DELETE FROM public.fee_concessions WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                DELETE FROM public.financial_period_charge_states WHERE school_id = p_school_id AND charge_id = ANY (v_charges);
                DELETE FROM public.charges WHERE school_id = p_school_id AND id = ANY (v_charges);
                DELETE FROM public.payments WHERE school_id = p_school_id AND id = ANY (v_payments);
                DELETE FROM public.payment_provider_events e WHERE e.school_id = p_school_id AND e.id = ANY (v_events)
                   AND NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.school_id = e.school_id AND p.provider_event_id = e.id);
                SELECT p.id INTO v_period FROM public.journal_entries j JOIN public.financial_periods p ON p.id = j.financial_period_id AND p.school_id = j.school_id
                 WHERE j.school_id = p_school_id AND j.id = ANY (v_entries) ORDER BY p.starts_on DESC LIMIT 1;
                DELETE FROM public.journal_entries WHERE school_id = p_school_id AND id = ANY (v_entries);
                PERFORM set_config('app.finance_retention_unit', '', true);

                INSERT INTO public.financial_period_expiries (id, school_id, financial_period_id, expired_at, charges, payments, journal_entries)
                VALUES (p_record_id, p_school_id, v_period, now() AT TIME ZONE 'UTC', cardinality(v_charges), cardinality(v_payments), cardinality(v_entries));

                RETURN cardinality(v_entries);
            END;
            $$;
            SQL);
        // The patched guards call it on every delete, including the runtime
        // role's ordinary draft-run item deletes; it only answers true inside
        // the definer function (owner privileges), so the runtime role may run it.
        DB::statement('REVOKE ALL ON FUNCTION finance_retention_delete_allowed() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION finance_retention_delete_allowed() TO school_os_app');
        DB::statement('REVOKE ALL ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) TO school_os_app');

        $this->patchGuards(self::ORIGINAL, self::PATCHED);
    }

    public function down(): void
    {
        if (DB::table('financial_period_expiries')->exists()) {
            throw new RuntimeException('Refusing to roll back: Finance detail has been expired; its lineage must stay with the baselines (ADR 0064 §15). Deleted evidence cannot be restored.');
        }

        $this->patchGuards(self::PATCHED, self::ORIGINAL);
        DB::statement('DROP FUNCTION IF EXISTS retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean)');
        DB::statement('DROP FUNCTION IF EXISTS finance_retention_delete_allowed()');
        TenantRls::disable('financial_period_expiries');
        Schema::dropIfExists('financial_period_expiries');
    }

    /** Rewrites the one depth check of each guard, keeping the rest of its definition verbatim. */
    private function patchGuards(string $from, string $to): void
    {
        foreach (self::GUARDED as $function) {
            $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regproc) AS d', [$function])->d;
            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException("{$function}: expected exactly one '{$from}'.");
            }
            DB::unprepared(str_replace($from, $to, $definition));
        }
    }
};
