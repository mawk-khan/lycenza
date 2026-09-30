<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FEE.3 (ADR 0062 §15, an ADR 0031 amendment; owner decision G1): the
     * PAYMENTS-owned charge capacity guard now counts live fee adjustments.
     *
     *   SUM(payment_allocations) + SUM(uncancelled fee_adjustments) <= charges.amount
     *
     * enforced at the insert of EITHER row. Both functions first lock the
     * charge row FOR UPDATE, so an allocation and an adjustment competing
     * for the last outstanding capacity serialize; the second re-reads the
     * committed totals and is refused. Payments owns this because Payments
     * -> Fees is the permitted dependency direction and Payments already
     * owns the charge-side allocation guards; Fees never reads
     * `payment_allocations` (CLAUDE.md rule 4).
     *
     * The adjustment trigger is named to fire before Fees'
     * `fee_adjustments_guard_trigger` (PostgreSQL orders same-event BEFORE
     * triggers by name), so Fees' validation runs under the charge lock. It
     * raises two distinct messages that Fees maps to typed exceptions:
     * "is fully settled" (`ChargeFullyPaidException`) and "would exceed its
     * outstanding amount" (`AdjustmentExceedsOutstandingException`). An
     * adjustment is refused, never reduced (G1).
     *
     * down() drops the adjustment trigger and restores the 0G.5 allocation
     * function body verbatim (rule 10).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_lock_and_validate_charge_allocation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_charge_amount numeric;
                v_cancelled_at timestamp;
                v_allocated_total numeric;
                v_adjusted_total numeric;
            BEGIN
                SELECT amount, cancelled_at INTO v_charge_amount, v_cancelled_at
                    FROM charges
                    WHERE id = NEW.charge_id
                    FOR UPDATE;

                IF NOT FOUND THEN
                    -- A nonexistent or cross-School charge_id is rejected
                    -- independently by payment_allocations_charge_fk
                    -- (Postgres FK/RI checks are not subject to RLS) --
                    -- nothing further to validate here.
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a new payment allocation', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO v_allocated_total
                    FROM payment_allocations
                    WHERE charge_id = NEW.charge_id;

                -- FEE.3 (ADR 0062 §15): live fee adjustments consume capacity too.
                SELECT coalesce(sum(amount), 0) INTO v_adjusted_total
                    FROM fee_adjustments
                    WHERE charge_id = NEW.charge_id AND cancelled_at IS NULL;

                IF v_allocated_total + v_adjusted_total + NEW.amount > v_charge_amount THEN
                    RAISE EXCEPTION
                        'charge % allocations (%) would exceed its own amount (%) net of fee adjustments (%)',
                        NEW.charge_id, v_allocated_total + NEW.amount, v_charge_amount, v_adjusted_total
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_lock_and_validate_charge_adjustment() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_charge_amount numeric;
                v_cancelled_at timestamp;
                v_allocated_total numeric;
                v_adjusted_total numeric;
            BEGIN
                SELECT amount, cancelled_at INTO v_charge_amount, v_cancelled_at
                    FROM charges
                    WHERE id = NEW.charge_id
                    FOR UPDATE;

                IF NOT FOUND THEN
                    -- fee_adjustments_charge_fk rejects a nonexistent or
                    -- cross-School charge independently of RLS.
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a fee adjustment', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO v_allocated_total
                    FROM payment_allocations
                    WHERE charge_id = NEW.charge_id;

                SELECT coalesce(sum(amount), 0) INTO v_adjusted_total
                    FROM fee_adjustments
                    WHERE charge_id = NEW.charge_id AND cancelled_at IS NULL;

                IF v_allocated_total + v_adjusted_total >= v_charge_amount THEN
                    RAISE EXCEPTION 'charge % is fully settled and cannot receive a fee adjustment', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_allocated_total + v_adjusted_total + NEW.amount > v_charge_amount THEN
                    RAISE EXCEPTION 'fee adjustment of charge % would exceed its outstanding amount', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_adjustments_capacity_trigger '.
            'BEFORE INSERT ON fee_adjustments '.
            'FOR EACH ROW EXECUTE FUNCTION payments_lock_and_validate_charge_adjustment()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_adjustments_capacity_trigger ON fee_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS payments_lock_and_validate_charge_adjustment()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_lock_and_validate_charge_allocation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_charge_amount numeric;
                v_cancelled_at timestamp;
                v_allocated_total numeric;
            BEGIN
                SELECT amount, cancelled_at INTO v_charge_amount, v_cancelled_at
                    FROM charges
                    WHERE id = NEW.charge_id
                    FOR UPDATE;

                IF NOT FOUND THEN
                    -- A nonexistent or cross-School charge_id is rejected
                    -- independently by payment_allocations_charge_fk
                    -- (Postgres FK/RI checks are not subject to RLS) --
                    -- nothing further to validate here.
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a new payment allocation', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO v_allocated_total
                    FROM payment_allocations
                    WHERE charge_id = NEW.charge_id;

                IF v_allocated_total + NEW.amount > v_charge_amount THEN
                    RAISE EXCEPTION
                        'charge % allocations (%) would exceed its own amount (%)',
                        NEW.charge_id, v_allocated_total + NEW.amount, v_charge_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
