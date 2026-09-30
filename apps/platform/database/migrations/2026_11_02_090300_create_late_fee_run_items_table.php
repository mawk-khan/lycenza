<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.5 (ADR 0062 §16.2; owner decision H): one item per candidate
     * source charge of a late-fee run, with its provenance: Student, year,
     * fee head and period, due date, final grace date (`due_date +
     * grace_days`), the preview outstanding, calculated amount, whether the
     * cap applied and the final amount -- and, at execution, the
     * execution-time outstanding and amounts actually used (preview is
     * never authority).
     *
     * - Closed catalogues (CHECKs): preview `ready | not_eligible |
     *   already_assessed` with reasons `grace_not_elapsed | fully_settled |
     *   zero_amount | already_assessed`; execution `pending -> succeeded |
     *   skipped_already_assessed | failed` with failure reasons
     *   `rule_not_active | source_not_eligible | grace_not_elapsed |
     *   fully_settled | zero_amount | account_invalid | error`.
     * - Phase immutability (`payments_guard_late_fee_run_item`): items are
     *   replaced only while the run is draft/previewed; once executing,
     *   preview facts are fixed and execution state only moves forward;
     *   terminal runs' items are immutable.
     * - No Student name or free text is stored.
     */
    public function up(): void
    {
        Schema::create('late_fee_run_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('late_fee_run_id');
            $table->uuid('source_charge_id');
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->string('billing_period_key', 32);
            $table->date('due_date');
            $table->date('final_grace_date');
            $table->decimal('outstanding_amount', 14, 2);
            $table->decimal('calculated_amount', 14, 2);
            $table->decimal('final_amount', 14, 2);
            $table->boolean('cap_applied')->default(false);
            $table->char('currency', 3)->default('INR');
            $table->string('preview_result');
            $table->string('reason')->nullable();
            $table->string('execution_status')->nullable();
            $table->string('failure_reason')->nullable();
            $table->decimal('executed_outstanding_amount', 14, 2)->nullable();
            $table->decimal('executed_amount', 14, 2)->nullable();
            $table->boolean('executed_cap_applied')->nullable();
            $table->uuid('late_fee_assessment_id')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['late_fee_run_id', 'source_charge_id'], 'late_fee_run_items_one_per_source');
            $table->index(['late_fee_run_id', 'execution_status']);
            $table->index('source_charge_id');
            $table->index('late_fee_assessment_id');

            $table->foreign(['late_fee_run_id', 'school_id'], 'late_fee_run_items_run_fk')->references(['id', 'school_id'])->on('late_fee_runs')->cascadeOnDelete();
            $table->foreign(['source_charge_id', 'school_id'], 'late_fee_run_items_source_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
            $table->foreign(['late_fee_assessment_id', 'school_id'], 'late_fee_run_items_assessment_fk')->references(['id', 'school_id'])->on('late_fee_assessments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE late_fee_run_items ADD CONSTRAINT late_fee_run_items_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement(
            'ALTER TABLE late_fee_run_items ADD CONSTRAINT late_fee_run_items_amounts_check CHECK ('.
            'outstanding_amount >= 0 AND calculated_amount >= 0 AND final_amount >= 0 AND final_amount <= calculated_amount AND '.
            '(executed_amount IS NULL OR executed_amount > 0) AND (executed_outstanding_amount IS NULL OR executed_outstanding_amount >= 0))'
        );
        DB::statement(
            'ALTER TABLE late_fee_run_items ADD CONSTRAINT late_fee_run_items_preview_shape_check CHECK ('.
            "preview_result IN ('ready', 'not_eligible', 'already_assessed') AND ".
            "(reason IS NULL OR reason IN ('grace_not_elapsed', 'fully_settled', 'zero_amount', 'already_assessed')) AND ".
            "((preview_result = 'ready') = (reason IS NULL)) AND ".
            "(preview_result <> 'ready' OR final_amount > 0))"
        );
        DB::statement(
            'ALTER TABLE late_fee_run_items ADD CONSTRAINT late_fee_run_items_execution_shape_check CHECK ('.
            "(execution_status IS NULL OR preview_result = 'ready') AND ".
            "(execution_status IS NULL OR execution_status IN ('pending', 'succeeded', 'skipped_already_assessed', 'failed')) AND ".
            "((COALESCE(execution_status, '') = 'succeeded') = (late_fee_assessment_id IS NOT NULL)) AND ".
            "((COALESCE(execution_status, '') = 'succeeded') = (executed_amount IS NOT NULL)) AND ".
            "((COALESCE(execution_status, '') = 'failed') = (failure_reason IS NOT NULL)) AND ".
            "(failure_reason IS NULL OR failure_reason IN ('rule_not_active', 'source_not_eligible', 'grace_not_elapsed', 'fully_settled', 'zero_amount', 'account_invalid', 'error')) AND ".
            "((COALESCE(execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed')) = (executed_at IS NOT NULL)))"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_guard_late_fee_run_item() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                run_status text;
                run_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;
                    run_id := OLD.late_fee_run_id;
                ELSE
                    run_id := NEW.late_fee_run_id;
                END IF;

                SELECT status INTO run_status FROM late_fee_runs WHERE id = run_id;

                IF TG_OP IN ('INSERT', 'DELETE') THEN
                    IF run_status NOT IN ('draft', 'previewed') THEN
                        RAISE EXCEPTION 'late-fee run % is % and its items are fixed', run_id, run_status
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF TG_OP = 'INSERT' AND NEW.execution_status IS NOT NULL THEN
                        RAISE EXCEPTION 'a late-fee item is created without execution state'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.late_fee_run_id IS DISTINCT FROM OLD.late_fee_run_id
                    OR NEW.source_charge_id IS DISTINCT FROM OLD.source_charge_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.billing_period_key IS DISTINCT FROM OLD.billing_period_key
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.final_grace_date IS DISTINCT FROM OLD.final_grace_date
                    OR NEW.outstanding_amount IS DISTINCT FROM OLD.outstanding_amount
                    OR NEW.calculated_amount IS DISTINCT FROM OLD.calculated_amount
                    OR NEW.final_amount IS DISTINCT FROM OLD.final_amount
                    OR NEW.cap_applied IS DISTINCT FROM OLD.cap_applied
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.preview_result IS DISTINCT FROM OLD.preview_result
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                THEN
                    RAISE EXCEPTION 'late-fee run item % preview facts are immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF run_status = 'executing' THEN
                    IF NOT (
                        (OLD.execution_status IS NULL AND COALESCE(NEW.execution_status, '') = 'pending')
                        OR (COALESCE(OLD.execution_status, '') = 'pending'
                            AND COALESCE(NEW.execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'illegal late-fee item execution transition % -> %', OLD.execution_status, NEW.execution_status
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'late-fee run % is % and its items are immutable', run_id, run_status
                    USING ERRCODE = 'check_violation';
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER late_fee_run_items_guard_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON late_fee_run_items '.
            'FOR EACH ROW EXECUTE FUNCTION payments_guard_late_fee_run_item()'
        );

        TenantRls::enable('late_fee_run_items');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS late_fee_run_items_guard_trigger ON late_fee_run_items');
        DB::statement('DROP FUNCTION IF EXISTS payments_guard_late_fee_run_item()');
        TenantRls::disable('late_fee_run_items');
        Schema::dropIfExists('late_fee_run_items');
    }
};
