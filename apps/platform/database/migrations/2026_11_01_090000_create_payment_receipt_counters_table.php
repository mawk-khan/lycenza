<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.4 (ADR 0062 §17.2, owner decision I): the Payments-owned receipt
     * counter -- one row per School x financial-year series, holding the
     * NEXT sequence value to allocate. `ReceiptIssuer` creates the row
     * (insert-or-ignore), locks it FOR UPDATE and increments it inside the
     * same transaction that inserts the receipt, so a rolled-back
     * transaction consumes no number (the `EmployeeNumberAllocator`
     * precedent). Never a PostgreSQL SEQUENCE, never MAX()+1.
     *
     * `prefix` snapshots the School's receipt prefix when the series
     * starts, so every receipt of one series shares one format even if the
     * setting later changes (it cannot while the series is current -- see
     * the payment_receipts migration's fee_settings guard).
     *
     * `payments_guard_receipt_counter` makes the gap-free rule structural:
     * a series starts at 1; the counter only moves by exactly +1, and only
     * once the receipt carrying the old value exists; identity, series and
     * prefix never change; a counter is never deleted (except by the
     * School's own cascade).
     */
    public function up(): void
    {
        Schema::create('payment_receipt_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('series_key', 7);
            $table->string('prefix', 16);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'series_key'], 'payment_receipt_counters_series_unique');
        });

        DB::statement('ALTER TABLE payment_receipt_counters ADD CONSTRAINT payment_receipt_counters_next_value_check CHECK (next_value >= 1)');
        DB::statement("ALTER TABLE payment_receipt_counters ADD CONSTRAINT payment_receipt_counters_series_key_check CHECK (series_key ~ '^[0-9]{4}-[0-9]{2}$')");
        DB::statement("ALTER TABLE payment_receipt_counters ADD CONSTRAINT payment_receipt_counters_prefix_check CHECK (prefix ~ '^[A-Z0-9][A-Z0-9-]{0,15}$')");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_guard_receipt_counter() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'receipt counter % is never deleted', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.next_value <> 1 THEN
                        RAISE EXCEPTION 'a receipt series always starts at 1'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.series_key IS DISTINCT FROM OLD.series_key
                    OR NEW.prefix IS DISTINCT FROM OLD.prefix
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'receipt counter % series identity is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.next_value <> OLD.next_value + 1 THEN
                    RAISE EXCEPTION 'receipt counter % only advances by one (% -> %)', OLD.id, OLD.next_value, NEW.next_value
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM payment_receipts
                    WHERE school_id = OLD.school_id AND series_key = OLD.series_key AND sequence_value = OLD.next_value
                ) THEN
                    RAISE EXCEPTION 'receipt counter % cannot advance past % without its receipt', OLD.id, OLD.next_value
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        // The function reads payment_receipts (next migration); PL/pgSQL
        // resolves it at run time.
        DB::statement(
            'CREATE TRIGGER payment_receipt_counters_guard_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON payment_receipt_counters '.
            'FOR EACH ROW EXECUTE FUNCTION payments_guard_receipt_counter()'
        );

        TenantRls::enable('payment_receipt_counters');
        TenantRls::revokeDelete('payment_receipt_counters');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_receipt_counters_guard_trigger ON payment_receipt_counters');
        DB::statement('DROP FUNCTION IF EXISTS payments_guard_receipt_counter()');
        TenantRls::disable('payment_receipt_counters');
        Schema::dropIfExists('payment_receipt_counters');
    }
};
