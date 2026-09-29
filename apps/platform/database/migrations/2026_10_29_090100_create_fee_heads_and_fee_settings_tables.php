<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.1 (ADR 0062 §5): `fee_heads`, the School-owned fee catalogue
     * (Tuition, Admission, Transport, ...), and the additive `fee_settings`
     * singleton (§14.5, §17.2) whose columns belong to FEE.3/FEE.4 and stay
     * nullable until then.
     *
     * A fee head maps every charge it later produces to one receivable
     * (`asset`) and one revenue (`income`) ledger account. The composite
     * `(id, school_id, currency)` foreign keys make a cross-School or
     * non-INR account structurally impossible; `fees_validate_fee_head_accounts`
     * additionally checks the account TYPES at write time, and
     * `ledger_accounts_fee_head_type_guard` refuses a later type change of
     * an account a fee head maps to. Account STATUS is deliberately not a
     * database invariant (an account may be deactivated later); the
     * Application layer requires active accounts at write time and again at
     * every assessment (ADR 0062 §5).
     *
     * Codes: `NormalizesCode` uppercases in the model, and the expression
     * index `fee_heads_school_id_code_ci_unique` makes uniqueness
     * case-insensitive for any writer (the `ledger_accounts` precedent).
     * Heads are deactivated, never deleted (`TenantRls::revokeDelete`).
     */
    public function up(): void
    {
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->uuid('receivable_ledger_account_id');
            $table->uuid('revenue_ledger_account_id');
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index('receivable_ledger_account_id');
            $table->index('revenue_ledger_account_id');

            $table->foreign(['receivable_ledger_account_id', 'school_id', 'currency'], 'fee_heads_receivable_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();

            $table->foreign(['revenue_ledger_account_id', 'school_id', 'currency'], 'fee_heads_revenue_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_heads ADD CONSTRAINT fee_heads_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE fee_heads ADD CONSTRAINT fee_heads_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_heads ADD CONSTRAINT fee_heads_distinct_accounts_check CHECK (receivable_ledger_account_id <> revenue_ledger_account_id)');
        DB::statement("ALTER TABLE fee_heads ADD CONSTRAINT fee_heads_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");
        DB::statement('CREATE UNIQUE INDEX fee_heads_school_id_code_ci_unique ON fee_heads (school_id, upper(code))');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_head_accounts() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM ledger_accounts
                    WHERE id = NEW.receivable_ledger_account_id AND school_id = NEW.school_id AND type = 'asset'
                ) THEN
                    RAISE EXCEPTION 'fee head receivable account % must be an asset account of the same School', NEW.receivable_ledger_account_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM ledger_accounts
                    WHERE id = NEW.revenue_ledger_account_id AND school_id = NEW.school_id AND type = 'income'
                ) THEN
                    RAISE EXCEPTION 'fee head revenue account % must be an income account of the same School', NEW.revenue_ledger_account_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_heads_account_type_trigger '.
            'BEFORE INSERT OR UPDATE OF receivable_ledger_account_id, revenue_ledger_account_id ON fee_heads '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_head_accounts()'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_reject_fee_head_account_type_change() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.type IS DISTINCT FROM OLD.type AND EXISTS (
                    SELECT 1 FROM fee_heads
                    WHERE receivable_ledger_account_id = OLD.id OR revenue_ledger_account_id = OLD.id
                ) THEN
                    RAISE EXCEPTION 'ledger account % is mapped by a fee head; its type cannot change', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER ledger_accounts_fee_head_type_guard '.
            'BEFORE UPDATE OF type ON ledger_accounts '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_fee_head_account_type_change()'
        );

        TenantRls::enable('fee_heads');
        TenantRls::revokeDelete('fee_heads');

        Schema::create('fee_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            // FEE.3 (ADR 0062 §14.5, decision F2 pending): the concession posting account.
            $table->uuid('concession_ledger_account_id')->nullable();
            // FEE.4 (ADR 0062 §17.2, decision I pending): receipt numbering.
            $table->string('receipt_prefix', 16)->nullable();
            $table->unsignedSmallInteger('financial_year_start_month')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique('school_id');
            $table->unique(['id', 'school_id']);

            $table->foreign(['concession_ledger_account_id', 'school_id', 'currency'], 'fee_settings_concession_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_settings ADD CONSTRAINT fee_settings_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_settings ADD CONSTRAINT fee_settings_fy_start_month_check CHECK (financial_year_start_month IS NULL OR financial_year_start_month BETWEEN 1 AND 12)');

        TenantRls::enable('fee_settings');
        TenantRls::revokeDelete('fee_settings');
    }

    public function down(): void
    {
        TenantRls::disable('fee_settings');
        Schema::dropIfExists('fee_settings');

        DB::statement('DROP TRIGGER IF EXISTS ledger_accounts_fee_head_type_guard ON ledger_accounts');
        DB::statement('DROP FUNCTION IF EXISTS fees_reject_fee_head_account_type_change()');
        DB::statement('DROP TRIGGER IF EXISTS fee_heads_account_type_trigger ON fee_heads');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_head_accounts()');
        TenantRls::disable('fee_heads');
        Schema::dropIfExists('fee_heads');
    }
};
