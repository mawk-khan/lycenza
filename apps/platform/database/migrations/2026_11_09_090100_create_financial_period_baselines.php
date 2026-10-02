<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E21.3A (ADR 0064): the carried-forward state a financial-period close
 * writes. It is authoritative Finance evidence: School-scoped, append-only,
 * and written only inside the close transaction while its period is still
 * open, so it is immutable once the period is closed.
 *
 * - `financial_period_account_balances`: per ledger account, the
 *   CUMULATIVE debit and credit totals of every journal line through the
 *   closed period. Account balance now = baseline of the latest closed
 *   period + lines of later periods.
 * - `financial_period_charge_states`: per charge assessed through the
 *   closed period, its state AS OF that period. That is the amount,
 *   allocations through it, live adjustments as of it, and whether it was
 *   cancelled by then. Charge outstanding now = that state + later
 *   allocations, adjustments, voids and cancellations. Student dues are
 *   the sum over charges, so no separate Student ledger exists.
 *
 * There is no independent purge: a baseline lives as long as later
 * financial state depends on it (ADR 0064 §8). Rollback refuses while any
 * baseline exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_period_account_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('financial_period_id');
            $table->uuid('ledger_account_id');
            $table->char('currency', 3);
            $table->decimal('debit_total', 20, 2);
            $table->decimal('credit_total', 20, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['financial_period_id', 'ledger_account_id', 'currency'], 'fpab_period_account_unique');
        });
        DB::statement('ALTER TABLE financial_period_account_balances ADD CONSTRAINT fpab_period_fk
            FOREIGN KEY (financial_period_id, school_id) REFERENCES financial_periods (id, school_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE financial_period_account_balances ADD CONSTRAINT fpab_account_fk
            FOREIGN KEY (ledger_account_id, school_id, currency) REFERENCES ledger_accounts (id, school_id, currency) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE financial_period_account_balances ADD CONSTRAINT fpab_totals_check CHECK (debit_total >= 0 AND credit_total >= 0)');

        Schema::create('financial_period_charge_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('financial_period_id');
            $table->uuid('charge_id');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('allocated_total', 20, 2);
            $table->decimal('live_adjusted_total', 20, 2);
            $table->boolean('cancelled');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['financial_period_id', 'charge_id'], 'fpcs_period_charge_unique');
            $table->index(['school_id', 'charge_id']);
        });
        DB::statement('ALTER TABLE financial_period_charge_states ADD CONSTRAINT fpcs_period_fk
            FOREIGN KEY (financial_period_id, school_id) REFERENCES financial_periods (id, school_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE financial_period_charge_states ADD CONSTRAINT fpcs_charge_fk
            FOREIGN KEY (charge_id, school_id) REFERENCES charges (id, school_id) ON DELETE RESTRICT');

        foreach (['financial_period_account_balances', 'financial_period_charge_states'] as $table) {
            TenantRls::enable($table);
            TenantRls::makeAppendOnly($table);
        }

        DB::unprepared(<<<'SQL'
            -- A baseline row is written only while its period is still OPEN (inside
            -- the close transaction, which holds the period FOR UPDATE).
            CREATE FUNCTION financial_period_baselines_open_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM financial_periods WHERE id = NEW.financial_period_id AND school_id = NEW.school_id AND status = 'open') THEN
                    RAISE EXCEPTION 'baselines are written only while closing an open period' USING ERRCODE = 'object_not_in_prerequisite_state';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_fpab_open_only BEFORE INSERT ON financial_period_account_balances
                FOR EACH ROW EXECUTE FUNCTION financial_period_baselines_open_only();
            CREATE TRIGGER trg_fpcs_open_only BEFORE INSERT ON financial_period_charge_states
                FOR EACH ROW EXECUTE FUNCTION financial_period_baselines_open_only();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('financial_period_account_balances')->exists() || DB::table('financial_period_charge_states')->exists()) {
            throw new RuntimeException('Refusing to roll back: financial-period baselines are authoritative Finance evidence (ADR 0064).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_fpcs_open_only ON financial_period_charge_states;
            DROP TRIGGER IF EXISTS trg_fpab_open_only ON financial_period_account_balances;
            DROP FUNCTION IF EXISTS financial_period_baselines_open_only();
            SQL);
        foreach (['financial_period_charge_states', 'financial_period_account_balances'] as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
