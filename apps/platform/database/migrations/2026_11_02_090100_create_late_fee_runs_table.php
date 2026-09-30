<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.5 (ADR 0062 §16.2; owner decision H): Payments-owned late-fee
     * runs -- one late-fee rule evaluated at one `evaluation_date` (a
     * School-calendar date, never later than today in the School's
     * timezone), staff-triggered; there is no scheduler.
     *
     * - Lifecycle, as §9: `draft -> previewed -> executing -> completed |
     *   completed_with_errors`, `previewed -> draft`, `draft|previewed ->
     *   cancelled`; terminal runs are immutable.
     * - One open run per rule (`late_fee_runs_one_open_per_rule`).
     * - `previewed_rule_version` snapshots the rule's
     *   `configuration_version` at preview; execution is refused when the
     *   rule changed since (stale preview).
     * - NUMERIC totals; RLS forced; never deleted.
     */
    public function up(): void
    {
        Schema::create('late_fee_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_late_fee_rule_id');
            $table->date('evaluation_date');
            $table->string('status')->default('draft');
            $table->unsignedInteger('previewed_rule_version')->nullable();

            $table->unsignedInteger('ready_count')->default(0);
            $table->decimal('ready_amount', 16, 2)->default(0);
            $table->unsignedInteger('not_eligible_count')->default(0);
            $table->unsignedInteger('already_assessed_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('assessed_amount', 16, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('previewed_at')->nullable();
            $table->foreignUuid('previewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('execution_started_at')->nullable();
            $table->foreignUuid('executed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index('fee_late_fee_rule_id');

            $table->foreign(['fee_late_fee_rule_id', 'school_id'], 'late_fee_runs_rule_fk')
                ->references(['id', 'school_id'])->on('fee_late_fee_rules')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE late_fee_runs ADD CONSTRAINT late_fee_runs_status_check CHECK (status IN ('draft', 'previewed', 'executing', 'completed', 'completed_with_errors', 'cancelled'))");
        DB::statement("ALTER TABLE late_fee_runs ADD CONSTRAINT late_fee_runs_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE late_fee_runs ADD CONSTRAINT late_fee_runs_amounts_check CHECK (ready_amount >= 0 AND assessed_amount >= 0)');
        DB::statement(
            'ALTER TABLE late_fee_runs ADD CONSTRAINT late_fee_runs_shape_check CHECK ('.
            "(status <> 'previewed' OR (previewed_at IS NOT NULL AND previewed_rule_version IS NOT NULL)) AND ".
            "(status NOT IN ('executing', 'completed', 'completed_with_errors') OR (execution_started_at IS NOT NULL AND previewed_rule_version IS NOT NULL)) AND ".
            "(status NOT IN ('completed', 'completed_with_errors') OR completed_at IS NOT NULL) AND ".
            "((status = 'cancelled') = (cancelled_at IS NOT NULL)))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX late_fee_runs_one_open_per_rule ON late_fee_runs '.
            "(school_id, fee_late_fee_rule_id) WHERE status IN ('draft', 'previewed', 'executing')"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_validate_late_fee_run() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_timezone text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'a late-fee run is always created as a draft'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT timezone INTO v_timezone FROM schools WHERE id = NEW.school_id;
                    IF NEW.evaluation_date > (now() AT TIME ZONE v_timezone)::date THEN
                        RAISE EXCEPTION 'a late-fee run cannot evaluate a future date (%)', NEW.evaluation_date
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status IN ('completed', 'completed_with_errors', 'cancelled') THEN
                    RAISE EXCEPTION 'late-fee run % is % and immutable', OLD.id, OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (
                    (OLD.status = NEW.status AND OLD.status IN ('draft', 'previewed', 'executing'))
                    OR (OLD.status = 'draft' AND NEW.status IN ('previewed', 'cancelled'))
                    OR (OLD.status = 'previewed' AND NEW.status IN ('draft', 'executing', 'cancelled'))
                    OR (OLD.status = 'executing' AND NEW.status IN ('completed', 'completed_with_errors'))
                ) THEN
                    RAISE EXCEPTION 'illegal late-fee run transition % -> %', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.fee_late_fee_rule_id IS DISTINCT FROM OLD.fee_late_fee_rule_id
                    OR NEW.evaluation_date IS DISTINCT FROM OLD.evaluation_date
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                THEN
                    RAISE EXCEPTION 'late-fee run % scope is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER late_fee_runs_lifecycle_trigger '.
            'BEFORE INSERT OR UPDATE ON late_fee_runs '.
            'FOR EACH ROW EXECUTE FUNCTION payments_validate_late_fee_run()'
        );

        TenantRls::enable('late_fee_runs');
        TenantRls::revokeDelete('late_fee_runs');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS late_fee_runs_lifecycle_trigger ON late_fee_runs');
        DB::statement('DROP FUNCTION IF EXISTS payments_validate_late_fee_run()');
        TenantRls::disable('late_fee_runs');
        Schema::dropIfExists('late_fee_runs');
    }
};
