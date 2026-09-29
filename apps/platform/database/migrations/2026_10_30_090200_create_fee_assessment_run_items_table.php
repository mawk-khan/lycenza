<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.2 (ADR 0062 §9.2): one preview/execution item per target Student
     * x structure line that has the run's billing period.
     *
     * - Provenance: Student, the enrollment chosen for the period and its
     *   campus/grade/start date, the structure line and instalment, fee
     *   head, period, and the instalment amount (NUMERIC, never
     *   prorated -- owner decision D1).
     * - Preview result and reason come from the closed catalogues of §9.2
     *   (`fee_assessment_run_items_preview_shape_check`): `ready` and
     *   `already_assessed` carry no reason; `excluded` carries
     *   optional_not_selected / period_before_enrollment /
     *   enrollment_cancelled / student_inactive / staff_excluded; `blocked`
     *   carries no_structure / head_inactive / account_invalid. A staff
     *   exclusion records its actor and time.
     * - Execution state (`pending -> succeeded | skipped_already_assessed |
     *   failed`) exists only on `ready` items; `succeeded` links its
     *   `fee_assessments` row; `failed` carries a closed failure reason.
     * - Phase immutability (`fees_guard_fee_assessment_run_item`): items
     *   are written and replaced only while the run is draft/previewed (and
     *   then only the exclusion fields may change); while executing only
     *   the execution fields may change, forward only; a terminal run's
     *   items never change. A School's cascade delete passes at trigger
     *   depth > 1.
     */
    public function up(): void
    {
        Schema::create('fee_assessment_run_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_assessment_run_id');
            $table->uuid('student_id');
            $table->uuid('student_enrollment_id')->nullable();
            $table->uuid('campus_id')->nullable();
            $table->uuid('grade_level_id');
            $table->date('enrollment_starts_on')->nullable();
            $table->uuid('fee_structure_line_id');
            $table->uuid('fee_structure_installment_id');
            $table->uuid('fee_head_id');
            $table->string('billing_period_key', 32);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('preview_result');
            $table->string('reason')->nullable();
            $table->foreignUuid('excluded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('excluded_at')->nullable();
            $table->string('execution_status')->nullable();
            $table->string('failure_reason')->nullable();
            $table->uuid('fee_assessment_id')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['fee_assessment_run_id', 'student_id', 'fee_structure_line_id'], 'fee_assessment_run_items_one_per_student_line');
            $table->index(['fee_assessment_run_id', 'preview_result']);
            $table->index(['fee_assessment_run_id', 'execution_status']);
            $table->index(['school_id', 'student_id']);

            $table->foreign(['fee_assessment_run_id', 'school_id'], 'fee_assessment_run_items_run_fk')->references(['id', 'school_id'])->on('fee_assessment_runs')->cascadeOnDelete();
            $table->foreign(['student_id', 'school_id'], 'fee_assessment_run_items_student_fk')->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['student_enrollment_id', 'school_id'], 'fee_assessment_run_items_enrollment_fk')->references(['id', 'school_id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['fee_structure_line_id', 'school_id'], 'fee_assessment_run_items_line_fk')->references(['id', 'school_id'])->on('fee_structure_lines')->restrictOnDelete();
            $table->foreign(['fee_structure_installment_id', 'school_id'], 'fee_assessment_run_items_installment_fk')->references(['id', 'school_id'])->on('fee_structure_installments')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_assessment_run_items_fee_head_fk')->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['fee_assessment_id', 'school_id'], 'fee_assessment_run_items_assessment_fk')->references(['id', 'school_id'])->on('fee_assessments')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_preview_shape_check CHECK ('.
            "(preview_result IN ('ready', 'already_assessed') AND reason IS NULL) OR ".
            "(preview_result = 'excluded' AND COALESCE(reason, '') IN ('optional_not_selected', 'period_before_enrollment', 'enrollment_cancelled', 'student_inactive', 'staff_excluded')) OR ".
            "(preview_result = 'blocked' AND COALESCE(reason, '') IN ('no_structure', 'head_inactive', 'account_invalid')))"
        );
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_exclusion_shape_check CHECK ('.
            "(COALESCE(reason, '') = 'staff_excluded') = (excluded_by_user_id IS NOT NULL AND excluded_at IS NOT NULL) AND ".
            '(excluded_by_user_id IS NULL) = (excluded_at IS NULL))'
        );
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_execution_shape_check CHECK ('.
            "(execution_status IS NULL OR preview_result = 'ready') AND ".
            "(execution_status IS NULL OR execution_status IN ('pending', 'succeeded', 'skipped_already_assessed', 'failed')) AND ".
            "((COALESCE(execution_status, '') = 'succeeded') = (fee_assessment_id IS NOT NULL)) AND ".
            "((COALESCE(execution_status, '') = 'failed') = (failure_reason IS NOT NULL)) AND ".
            "(failure_reason IS NULL OR failure_reason IN ('structure_not_active', 'structure_not_resolved', 'enrollment_not_qualifying', 'student_inactive', 'optional_not_selected', 'head_inactive', 'account_invalid', 'error')) AND ".
            "((COALESCE(execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed')) = (executed_at IS NOT NULL)))"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_guard_fee_assessment_run_item() RETURNS trigger
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
                    run_id := OLD.fee_assessment_run_id;
                ELSE
                    run_id := NEW.fee_assessment_run_id;
                END IF;

                SELECT status INTO run_status FROM fee_assessment_runs WHERE id = run_id;

                IF TG_OP IN ('INSERT', 'DELETE') THEN
                    IF run_status NOT IN ('draft', 'previewed') THEN
                        RAISE EXCEPTION 'assessment run % is % and its items are fixed', run_id, run_status
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF TG_OP = 'INSERT' AND NEW.execution_status IS NOT NULL THEN
                        RAISE EXCEPTION 'an item is created without execution state'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.fee_assessment_run_id IS DISTINCT FROM OLD.fee_assessment_run_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.student_enrollment_id IS DISTINCT FROM OLD.student_enrollment_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.enrollment_starts_on IS DISTINCT FROM OLD.enrollment_starts_on
                    OR NEW.fee_structure_line_id IS DISTINCT FROM OLD.fee_structure_line_id
                    OR NEW.fee_structure_installment_id IS DISTINCT FROM OLD.fee_structure_installment_id
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.billing_period_key IS DISTINCT FROM OLD.billing_period_key
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                THEN
                    RAISE EXCEPTION 'assessment run item % provenance is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF run_status IN ('draft', 'previewed') THEN
                    -- Only a staff exclusion of a ready item.
                    -- COALESCE: a NULL comparison must never read as "allowed".
                    IF NEW.execution_status IS NOT NULL
                       OR NOT (OLD.preview_result = 'ready' AND NEW.preview_result = 'excluded'
                               AND COALESCE(NEW.reason, '') = 'staff_excluded') THEN
                        RAISE EXCEPTION 'only a ready item can be excluded by staff before execution'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF run_status = 'executing' THEN
                    IF NEW.preview_result IS DISTINCT FROM OLD.preview_result
                        OR NEW.reason IS DISTINCT FROM OLD.reason
                        OR NEW.excluded_by_user_id IS DISTINCT FROM OLD.excluded_by_user_id
                        OR NEW.excluded_at IS DISTINCT FROM OLD.excluded_at
                    THEN
                        RAISE EXCEPTION 'preview facts are fixed once a run executes'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT (
                        (OLD.execution_status IS NULL AND COALESCE(NEW.execution_status, '') = 'pending')
                        OR (COALESCE(OLD.execution_status, '') = 'pending'
                            AND COALESCE(NEW.execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'illegal item execution transition % -> %', OLD.execution_status, NEW.execution_status
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'assessment run % is % and its items are immutable', run_id, run_status
                    USING ERRCODE = 'check_violation';
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_assessment_run_items_guard_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON fee_assessment_run_items '.
            'FOR EACH ROW EXECUTE FUNCTION fees_guard_fee_assessment_run_item()'
        );

        TenantRls::enable('fee_assessment_run_items');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_assessment_run_items_guard_trigger ON fee_assessment_run_items');
        DB::statement('DROP FUNCTION IF EXISTS fees_guard_fee_assessment_run_item()');
        TenantRls::disable('fee_assessment_run_items');
        Schema::dropIfExists('fee_assessment_run_items');
    }
};
