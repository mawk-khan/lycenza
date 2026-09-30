<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.4 (ADR 0062 §17; owner decisions I, I2): `payment_receipts`, the
     * Payments-owned evidence that one Payment settled. It records no
     * amount and no Student snapshot -- it reads them from the Payment and
     * its allocations -- and it is immutable forever.
     *
     * - One receipt per Payment (`payment_receipts_payment_unique`), same
     *   School by composite FK.
     * - `receipt_number` = `<prefix>/<series_key>/<sequence>`; the prefix
     *   is the series counter's snapshot; the sequence prints with at least
     *   six digits and never wraps (`payments_receipt_number()`).
     * - `series_key` is the School financial year of the Payment's
     *   `settled_at` in the School's timezone
     *   (`payments_fy_series_key()`, ADR 0062 correction 2026-09-30:
     *   `<starting year>-<next year's last two digits>` for every start
     *   month); never an AcademicYear.
     * - At insert (`payments_validate_payment_receipt`): the series matches
     *   the Payment, the counter row exists and the sequence is exactly the
     *   counter's next value, and the number is exactly the formatted
     *   value. With the counter guard this makes numbering gap-free and
     *   consecutive by construction.
     * - Immutable: runtime UPDATE/DELETE revoked (`makeAppendOnly`) and a
     *   trigger refusing any UPDATE, and any DELETE except the School's own
     *   cascade. There is no void, status, tax or free-text column (J:
     *   DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED).
     *
     * `fee_settings` receipt fields (FEE.1, Fees-owned) are guarded here,
     * the Payments-owned cross-boundary trigger pattern of
     * `charges_payment_allocation_guard_trigger`:
     * - the prefix format CHECK (`fee_settings_receipt_prefix_format_check`);
     * - `fee_settings_receipt_numbering_guard_trigger`: the prefix cannot
     *   change while the School's CURRENT series has receipts, and the
     *   financial-year start month cannot change once any receipt exists
     *   (it would redefine existing series).
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payment_id');
            $table->string('receipt_number', 40);
            $table->string('series_key', 7);
            $table->unsignedBigInteger('sequence_value');
            $table->timestamp('issued_at');
            $table->foreignUuid('issued_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['id', 'school_id']);
            $table->unique('payment_id', 'payment_receipts_payment_unique');
            $table->unique(['school_id', 'receipt_number'], 'payment_receipts_number_unique');
            $table->unique(['school_id', 'series_key', 'sequence_value'], 'payment_receipts_sequence_unique');
            $table->index(['school_id', 'issued_at']);

            $table->foreign(['payment_id', 'school_id'], 'payment_receipts_payment_fk')
                ->references(['id', 'school_id'])->on('payments')
                ->restrictOnDelete();
            $table->foreign(['school_id', 'series_key'], 'payment_receipts_counter_fk')
                ->references(['school_id', 'series_key'])->on('payment_receipt_counters')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_sequence_positive_check CHECK (sequence_value >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_fy_series_key(p_local timestamp, p_start_month integer) RETURNS text
            LANGUAGE plpgsql
            IMMUTABLE
            AS $$
            DECLARE
                y integer := extract(year FROM p_local)::integer;
            BEGIN
                IF extract(month FROM p_local)::integer < p_start_month THEN
                    y := y - 1;
                END IF;

                RETURN y::text || '-' || lpad(((y + 1) % 100)::text, 2, '0');
            END;
            $$;

            CREATE FUNCTION payments_receipt_number(p_prefix text, p_series_key text, p_sequence bigint) RETURNS text
            LANGUAGE sql
            IMMUTABLE
            AS $$
                SELECT p_prefix || '/' || p_series_key || '/' ||
                    CASE WHEN p_sequence < 1000000 THEN lpad(p_sequence::text, 6, '0') ELSE p_sequence::text END
            $$;

            CREATE FUNCTION payments_validate_payment_receipt() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_settled_at timestamp;
                v_timezone text;
                v_start_month integer;
                v_prefix text;
                v_next bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'payment receipt % is never deleted', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'payment receipt % is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT p.settled_at, s.timezone INTO v_settled_at, v_timezone
                    FROM payments p JOIN schools s ON s.id = p.school_id
                    WHERE p.id = NEW.payment_id AND p.school_id = NEW.school_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'payment receipt needs a Payment of the same School'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(financial_year_start_month, 4) INTO v_start_month
                    FROM fee_settings WHERE school_id = NEW.school_id;
                v_start_month := coalesce(v_start_month, 4);

                IF NEW.series_key IS DISTINCT FROM payments_fy_series_key((v_settled_at AT TIME ZONE 'UTC') AT TIME ZONE v_timezone, v_start_month) THEN
                    RAISE EXCEPTION 'payment receipt series % is not the financial year of its Payment', NEW.series_key
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT prefix, next_value INTO v_prefix, v_next
                    FROM payment_receipt_counters
                    WHERE school_id = NEW.school_id AND series_key = NEW.series_key;
                IF NOT FOUND OR NEW.sequence_value IS DISTINCT FROM v_next THEN
                    RAISE EXCEPTION 'payment receipt sequence % is not the next value of its series', NEW.sequence_value
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.receipt_number IS DISTINCT FROM payments_receipt_number(v_prefix, NEW.series_key, NEW.sequence_value) THEN
                    RAISE EXCEPTION 'payment receipt number % does not match its series format', NEW.receipt_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER payment_receipts_guard_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON payment_receipts '.
            'FOR EACH ROW EXECUTE FUNCTION payments_validate_payment_receipt()'
        );

        DB::statement("ALTER TABLE fee_settings ADD CONSTRAINT fee_settings_receipt_prefix_format_check CHECK (receipt_prefix IS NULL OR receipt_prefix ~ '^[A-Z0-9][A-Z0-9-]{0,15}$')");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_guard_receipt_numbering_settings() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_old_prefix text := 'RCPT';
                v_old_month integer := 4;
                v_timezone text;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    v_old_prefix := coalesce(OLD.receipt_prefix, 'RCPT');
                    v_old_month := coalesce(OLD.financial_year_start_month, 4);
                END IF;

                IF coalesce(NEW.financial_year_start_month, 4) <> v_old_month
                    AND EXISTS (SELECT 1 FROM payment_receipt_counters WHERE school_id = NEW.school_id) THEN
                    RAISE EXCEPTION 'the receipt financial-year start month cannot change once receipts exist'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF coalesce(NEW.receipt_prefix, 'RCPT') <> v_old_prefix THEN
                    SELECT timezone INTO v_timezone FROM schools WHERE id = NEW.school_id;
                    IF EXISTS (
                        SELECT 1 FROM payment_receipt_counters
                        WHERE school_id = NEW.school_id
                          AND series_key = payments_fy_series_key(now() AT TIME ZONE v_timezone, v_old_month)
                    ) THEN
                        RAISE EXCEPTION 'the receipt prefix cannot change while the current receipt series has receipts'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_settings_receipt_numbering_guard_trigger '.
            'BEFORE INSERT OR UPDATE OF receipt_prefix, financial_year_start_month ON fee_settings '.
            'FOR EACH ROW EXECUTE FUNCTION payments_guard_receipt_numbering_settings()'
        );

        TenantRls::enable('payment_receipts');
        TenantRls::makeAppendOnly('payment_receipts');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_settings_receipt_numbering_guard_trigger ON fee_settings');
        DB::statement('DROP FUNCTION IF EXISTS payments_guard_receipt_numbering_settings()');
        DB::statement('ALTER TABLE fee_settings DROP CONSTRAINT IF EXISTS fee_settings_receipt_prefix_format_check');
        DB::statement('DROP TRIGGER IF EXISTS payment_receipts_guard_trigger ON payment_receipts');
        DB::statement('DROP FUNCTION IF EXISTS payments_validate_payment_receipt()');
        TenantRls::disable('payment_receipts');
        Schema::dropIfExists('payment_receipts');
        DB::statement('DROP FUNCTION IF EXISTS payments_receipt_number(text, text, bigint)');
        DB::statement('DROP FUNCTION IF EXISTS payments_fy_series_key(timestamp, integer)');
    }
};
