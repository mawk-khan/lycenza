<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UAT-C3 (UAT-FIN-05/24/27; ADR 0031 amendment §3, ADR 0075 §16): explicit,
 * compensating payment corrections. Posted Payments, their allocations and
 * receipts stay exactly as recorded (append-only, frozen allocation set);
 * a correction is a NEW record that moves part of a Payment's effect:
 *
 * - `payment_corrections`: one per action -- `reallocation` (money valid,
 *   applied to the wrong charge), `reversal` (the Payment should not stand;
 *   at most one per Payment, and nothing after it) or `refund` (money that
 *   actually left the School). Each carries its own journal entry (so the
 *   period close and the carry-forward read date it), a required reason, the
 *   acting User and a server-issued idempotency key.
 * - `payment_correction_lines`: `release` takes an amount of that Payment off
 *   a charge; `apply` puts it on another. A charge's effective payment
 *   allocation = original allocations + applied - released
 *   (`payments_effective_allocated()`), and every capacity rule uses it.
 *
 * Database-enforced, whatever the caller:
 * - a line belongs to a correction of the same Payment and is written in
 *   the correction's own transaction (frozen afterwards, like allocations);
 * - `apply`: never onto a cancelled charge, never beyond the charge's amount
 *   net of live concession adjustments; `release`: never more than that
 *   Payment's effective amount on that charge;
 * - at commit the lines balance: a reallocation releases and applies the
 *   same amount; a refund and a reversal only release; a reversal leaves the
 *   Payment's effective allocation at zero;
 * - nothing corrects a reversed Payment; one reversal per Payment;
 * - a charge is cancellable only once its EFFECTIVE allocation is zero;
 * - the Finance D8 retention unit refuses (as a dependency) any unit with a
 *   corrected Payment or a corrected charge -- no unit deletes a correction
 *   until a later contract says how.
 *
 * Both tables are RLS-protected and append-only for the runtime role; the
 * retention identity may read them (the charge-state read it runs).
 */
return new class extends Migration
{
    private const RETENTION_FROM = "\n       OR EXISTS (SELECT 1 FROM public.library_fines WHERE school_id = p_school_id AND charge_id = ANY (v_charges))";

    private const RETENTION_TO = self::RETENTION_FROM
        ."\n       OR EXISTS (SELECT 1 FROM public.payment_corrections WHERE school_id = p_school_id AND payment_id = ANY (v_payments))"
        ."\n       OR EXISTS (SELECT 1 FROM public.payment_correction_lines WHERE school_id = p_school_id AND charge_id = ANY (v_charges))";

    public function up(): void
    {
        Schema::create('payment_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payment_id');
            $table->string('kind', 16);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->uuid('journal_entry_id');
            $table->uuid('settlement_ledger_account_id')->nullable();
            $table->string('refund_method', 32)->nullable();
            $table->string('refund_reference', 64)->nullable();
            $table->date('refunded_on')->nullable();
            $table->text('reason');
            $table->uuid('corrected_by_user_id');
            $table->uuid('idempotency_key');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'idempotency_key'], 'payment_corrections_idempotency_unique');
            $table->unique(['school_id', 'journal_entry_id'], 'payment_corrections_journal_entry_unique');
            $table->index(['school_id', 'payment_id']);

            $table->foreign(['payment_id', 'school_id'], 'payment_corrections_payment_fk')
                ->references(['id', 'school_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['journal_entry_id', 'school_id', 'currency'], 'payment_corrections_journal_entry_fk')
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['settlement_ledger_account_id', 'school_id', 'currency'], 'payment_corrections_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('corrected_by_user_id', 'payment_corrections_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_corrections
                ADD CONSTRAINT payment_corrections_amount_positive_check CHECK (amount > 0),
                ADD CONSTRAINT payment_corrections_currency_inr_only_check CHECK (currency = 'INR'),
                ADD CONSTRAINT payment_corrections_reason_check CHECK (char_length(btrim(reason)) BETWEEN 5 AND 500),
                ADD CONSTRAINT payment_corrections_kind_shape_check CHECK (
                    (kind = 'reallocation' AND settlement_ledger_account_id IS NULL AND refund_method IS NULL
                        AND refund_reference IS NULL AND refunded_on IS NULL)
                    OR (kind = 'reversal' AND settlement_ledger_account_id IS NOT NULL AND refund_method IS NULL
                        AND refund_reference IS NULL AND refunded_on IS NULL)
                    OR (kind = 'refund' AND settlement_ledger_account_id IS NOT NULL
                        AND refund_method IN ('cash', 'bank_transfer', 'cheque') AND refunded_on IS NOT NULL)
                );
            ALTER TABLE payment_corrections ADD COLUMN creation_txid xid8 NOT NULL DEFAULT pg_current_xact_id();
            CREATE UNIQUE INDEX payment_corrections_one_reversal ON payment_corrections (payment_id) WHERE kind = 'reversal';
            SQL);

        Schema::create('payment_correction_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payment_correction_id');
            $table->uuid('payment_id');
            $table->uuid('charge_id');
            $table->string('direction', 8);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'payment_correction_id']);
            $table->index(['school_id', 'payment_id']);
            $table->index(['school_id', 'charge_id']);

            $table->foreign(['payment_correction_id', 'school_id'], 'payment_correction_lines_correction_fk')
                ->references(['id', 'school_id'])->on('payment_corrections')->restrictOnDelete();
            $table->foreign(['payment_id', 'school_id'], 'payment_correction_lines_payment_fk')
                ->references(['id', 'school_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'payment_correction_lines_charge_fk')
                ->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_correction_lines
                ADD CONSTRAINT payment_correction_lines_amount_positive_check CHECK (amount > 0),
                ADD CONSTRAINT payment_correction_lines_currency_inr_only_check CHECK (currency = 'INR'),
                ADD CONSTRAINT payment_correction_lines_direction_check CHECK (direction IN ('release', 'apply'));

            -- A charge's effective payment allocation: original allocations plus
            -- correction lines applied minus released. Invoker rights: the
            -- caller's RLS scope (always the charge's own School).
            CREATE FUNCTION payments_effective_allocated(p_charge_id uuid) RETURNS numeric
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, pg_temp AS $$
                SELECT coalesce((SELECT sum(amount) FROM public.payment_allocations WHERE charge_id = p_charge_id), 0)
                     + coalesce((SELECT sum(CASE direction WHEN 'apply' THEN amount ELSE -amount END)
                                   FROM public.payment_correction_lines WHERE charge_id = p_charge_id), 0)
            $$;

            -- One Payment's effective amount on one charge (null charge: on all its charges).
            CREATE FUNCTION payments_effective_payment_allocated(p_payment_id uuid, p_charge_id uuid) RETURNS numeric
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, pg_temp AS $$
                SELECT coalesce((SELECT sum(amount) FROM public.payment_allocations
                                  WHERE payment_id = p_payment_id AND (p_charge_id IS NULL OR charge_id = p_charge_id)), 0)
                     + coalesce((SELECT sum(CASE direction WHEN 'apply' THEN amount ELSE -amount END)
                                   FROM public.payment_correction_lines
                                  WHERE payment_id = p_payment_id AND (p_charge_id IS NULL OR charge_id = p_charge_id)), 0)
            $$;

            CREATE FUNCTION payments_validate_correction_header() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER AS $$
            BEGIN
                NEW.creation_txid := pg_current_xact_id();

                IF EXISTS (SELECT 1 FROM payment_corrections WHERE payment_id = NEW.payment_id AND kind = 'reversal') THEN
                    RAISE EXCEPTION 'payment_corrections: payment % has been reversed and cannot be corrected further', NEW.payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_corrections_validate_header
                BEFORE INSERT ON payment_corrections
                FOR EACH ROW EXECUTE FUNCTION payments_validate_correction_header();

            CREATE FUNCTION payments_validate_correction_line() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE
                v_payment uuid;
                v_txid xid8;
                v_charge_amount numeric;
                v_cancelled_at timestamp;
                v_adjusted numeric;
            BEGIN
                SELECT payment_id, creation_txid INTO v_payment, v_txid FROM payment_corrections WHERE id = NEW.payment_correction_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'payment_correction_lines: correction % not found', NEW.payment_correction_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF v_payment <> NEW.payment_id THEN
                    RAISE EXCEPTION 'payment_correction_lines: line payment does not match its correction'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF v_txid <> pg_current_xact_id() THEN
                    RAISE EXCEPTION 'payment_correction_lines: correction % is frozen; lines are written only with it', NEW.payment_correction_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT amount, cancelled_at INTO v_charge_amount, v_cancelled_at FROM charges WHERE id = NEW.charge_id FOR UPDATE;
                IF NOT FOUND THEN
                    RETURN NEW; -- an unknown or cross-School charge is refused by the composite foreign key
                END IF;

                IF NEW.direction = 'apply' THEN
                    IF v_cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'payment_correction_lines: charge % is cancelled and cannot receive a payment', NEW.charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT coalesce(sum(amount), 0) INTO v_adjusted FROM fee_adjustments WHERE charge_id = NEW.charge_id AND cancelled_at IS NULL;
                    IF payments_effective_allocated(NEW.charge_id) + v_adjusted + NEW.amount > v_charge_amount THEN
                        RAISE EXCEPTION 'payment_correction_lines: charge % would be over-allocated', NEW.charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF payments_effective_payment_allocated(NEW.payment_id, NEW.charge_id) < NEW.amount THEN
                    RAISE EXCEPTION 'payment_correction_lines: payment % has less than % on charge %', NEW.payment_id, NEW.amount, NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_correction_lines_validate
                BEFORE INSERT ON payment_correction_lines
                FOR EACH ROW EXECUTE FUNCTION payments_validate_correction_line();

            CREATE FUNCTION payments_check_correction_balanced() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE
                v_release numeric;
                v_apply numeric;
            BEGIN
                SELECT coalesce(sum(amount) FILTER (WHERE direction = 'release'), 0),
                       coalesce(sum(amount) FILTER (WHERE direction = 'apply'), 0)
                  INTO v_release, v_apply
                  FROM payment_correction_lines WHERE payment_correction_id = NEW.id;

                IF v_release <> NEW.amount
                   OR (NEW.kind = 'reallocation' AND v_apply <> NEW.amount)
                   OR (NEW.kind IN ('reversal', 'refund') AND v_apply <> 0)
                   OR (NEW.kind = 'reversal' AND payments_effective_payment_allocated(NEW.payment_id, NULL) <> 0) THEN
                    RAISE EXCEPTION 'payment_corrections: correction % lines do not balance its % of %', NEW.id, NEW.kind, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER payment_corrections_balanced_check
                AFTER INSERT ON payment_corrections
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION payments_check_correction_balanced();
            SQL);

        $this->defineCapacityFunctions(effective: true);

        // E21-RH.7 (ADR 0066 §15): the database-recorded anchors (links tracked)
        // and the retention delete guards, as every Finance evidence table has.
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_corrections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON payment_corrections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('currency', 'journal_entry_id', 'payment_id', 'settlement_ledger_account_id');
            CREATE TRIGGER trg_retention_guard_payment_corrections AFTER DELETE ON payment_corrections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            ALTER TABLE payment_correction_lines ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON payment_correction_lines FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('charge_id', 'payment_correction_id', 'payment_id');
            CREATE TRIGGER trg_retention_guard_payment_correction_lines AFTER DELETE ON payment_correction_lines
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('payment_corrections');
        TenantRls::makeAppendOnly('payment_corrections');
        TenantRls::enable('payment_correction_lines');
        TenantRls::makeAppendOnly('payment_correction_lines');
        DB::unprepared(<<<'SQL'
            GRANT EXECUTE ON FUNCTION payments_effective_allocated(uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION payments_effective_payment_allocated(uuid, uuid) TO school_os_app;
            GRANT SELECT ON payment_corrections, payment_correction_lines TO school_os_retention;
            SQL);

        $this->replaceInFunction('retention_expire_finance_unit', self::RETENTION_FROM, self::RETENTION_TO);
    }

    public function down(): void
    {
        if (DB::table('payment_corrections')->exists()) {
            throw new RuntimeException('Cannot roll back payment corrections: correction records exist and are part of the financial history. Remove them deliberately first.');
        }

        $this->replaceInFunction('retention_expire_finance_unit', self::RETENTION_TO, self::RETENTION_FROM);
        $this->defineCapacityFunctions(effective: false);

        DB::unprepared(<<<'SQL'
            REVOKE SELECT ON payment_corrections, payment_correction_lines FROM school_os_retention;
            DROP TABLE payment_correction_lines;
            DROP TABLE payment_corrections;
            DROP FUNCTION payments_check_correction_balanced();
            DROP FUNCTION payments_validate_correction_line();
            DROP FUNCTION payments_validate_correction_header();
            DROP FUNCTION payments_effective_payment_allocated(uuid, uuid);
            DROP FUNCTION payments_effective_allocated(uuid);
            SQL);
    }

    /**
     * The allocation-capacity, adjustment-capacity and cancellation guards,
     * with a charge's allocated total either EFFECTIVE (after corrections)
     * or, for rollback, the original sum of `payment_allocations`.
     */
    private function defineCapacityFunctions(bool $effective): void
    {
        $allocated = $effective
            ? 'v_allocated_total := payments_effective_allocated(NEW.charge_id);'
            : 'SELECT coalesce(sum(amount), 0) INTO v_allocated_total FROM payment_allocations WHERE charge_id = NEW.charge_id;';
        $cancelGuard = $effective
            ? 'payments_effective_allocated(OLD.id) > 0'
            : 'EXISTS (SELECT 1 FROM payment_allocations WHERE charge_id = OLD.id)';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION payments_lock_and_validate_charge_allocation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS \$\$
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
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a new payment allocation', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                {$allocated}

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
            \$\$;

            CREATE OR REPLACE FUNCTION payments_lock_and_validate_charge_adjustment() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS \$\$
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
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a fee adjustment', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                {$allocated}

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
            \$\$;

            CREATE OR REPLACE FUNCTION payments_reject_charge_cancellation_with_allocations() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS \$\$
            BEGIN
                IF NEW.cancelled_at IS NOT NULL AND OLD.cancelled_at IS NULL AND {$cancelGuard} THEN
                    RAISE EXCEPTION 'charge % cannot be cancelled: recognized payment allocations exist', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$;
            SQL);
    }

    /** The finance retention function, amended in place against an exact body shape (as the OPF.4 migration does). */
    private function replaceInFunction(string $function, string $from, string $to): void
    {
        $definition = (string) DB::selectOne(
            "SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->d;
        if (substr_count($definition, $from) !== 1 || ($from === self::RETENTION_FROM && str_contains($definition, 'public.payment_corrections'))) {
            throw new RuntimeException("{$function}: unexpected body shape; refusing to change it.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }
};
