<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UAT-C6 (UAT-FIN-20, ADR 0075 section 19; owner decision: no automatic
     * exclusion, no silent inclusion). A run item whose Student already has
     * an uncancelled charge that may cover the same period is previewed as
     * `review` (`potential_overlap`) and needs an explicit, durable decision
     * by someone holding `finance.fee_assessments.run`:
     *
     * - include anyway -> `ready`, `overlap_decision = include`;
     * - exclude from this run -> `excluded` / `overlap_excluded`,
     *   `overlap_decision = exclude`.
     *
     * `overlap_charge_ids` is the provenance: the uncancelled charges seen at
     * preview (immutable once written). An "include anyway" item may still be
     * excluded before execution; that records an "exclude" decision. A decision records who and when.
     * Execution re-checks: a charge outside that set, or any overlap on an
     * undecided item, fails the item closed with `overlap_review_required`
     * (never charged). Phase immutability (`fees_guard_fee_assessment_run_item`)
     * gains the two decision transitions and nothing else.
     */
    private const BASE_FAILURES = "'structure_not_active', 'structure_not_resolved', 'enrollment_not_qualifying', 'student_inactive', 'optional_not_selected', 'head_inactive', 'account_invalid', 'error', 'concession_exceeds_outstanding', 'concession_account_invalid'";

    private const BASE_EXCLUDED = "'optional_not_selected', 'period_before_enrollment', 'enrollment_cancelled', 'student_inactive', 'staff_excluded'";

    public function up(): void
    {
        DB::statement('ALTER TABLE fee_assessment_run_items ADD COLUMN overlap_charge_ids jsonb NULL');
        DB::statement('ALTER TABLE fee_assessment_run_items ADD COLUMN overlap_decision varchar(16) NULL');
        DB::statement('ALTER TABLE fee_assessment_run_items ADD COLUMN overlap_decided_by_user_id uuid NULL REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE fee_assessment_run_items ADD COLUMN overlap_decided_at timestamp(0) without time zone NULL');

        $this->previewShape("(preview_result = 'review' AND COALESCE(reason, '') = 'potential_overlap') OR ", self::BASE_EXCLUDED.", 'overlap_excluded'");
        $this->executionShape(self::BASE_FAILURES.", 'overlap_review_required'");

        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_overlap_shape_check CHECK ('.
            "(overlap_decision IS NULL OR overlap_decision IN ('include', 'exclude')) AND ".
            '((overlap_decision IS NULL) = (overlap_decided_by_user_id IS NULL)) AND '.
            '((overlap_decision IS NULL) = (overlap_decided_at IS NULL)) AND '.
            "(overlap_charge_ids IS NULL OR (jsonb_typeof(overlap_charge_ids) = 'array' AND jsonb_array_length(overlap_charge_ids) > 0)) AND ".
            "(preview_result <> 'review' OR (overlap_charge_ids IS NOT NULL AND overlap_decision IS NULL)) AND ".
            "(COALESCE(overlap_decision, '') <> 'include' OR (preview_result = 'ready' AND overlap_charge_ids IS NOT NULL)) AND ".
            "((COALESCE(overlap_decision, '') = 'exclude') = (preview_result = 'excluded' AND COALESCE(reason, '') = 'overlap_excluded')) AND ".
            "(COALESCE(overlap_decision, '') <> 'exclude' OR overlap_charge_ids IS NOT NULL) AND ".
            "(overlap_charge_ids IS NULL OR preview_result IN ('review', 'ready', 'excluded')))"
        );

        $this->guard(withOverlap: true);
    }

    public function down(): void
    {
        // Fail-closed like FEE.3's catalogue migration: once a run carries a
        // review item, an overlap decision or an overlap failure, the
        // narrower CHECKs below refuse, rather than rewriting history.
        $this->guard(withOverlap: false);
        DB::statement('ALTER TABLE fee_assessment_run_items DROP CONSTRAINT fee_assessment_run_items_overlap_shape_check');
        $this->executionShape(self::BASE_FAILURES);
        $this->previewShape('', self::BASE_EXCLUDED);
        DB::statement('ALTER TABLE fee_assessment_run_items DROP COLUMN overlap_decided_at');
        DB::statement('ALTER TABLE fee_assessment_run_items DROP COLUMN overlap_decided_by_user_id');
        DB::statement('ALTER TABLE fee_assessment_run_items DROP COLUMN overlap_decision');
        DB::statement('ALTER TABLE fee_assessment_run_items DROP COLUMN overlap_charge_ids');
    }

    private function previewShape(string $review, string $excluded): void
    {
        DB::statement('ALTER TABLE fee_assessment_run_items DROP CONSTRAINT fee_assessment_run_items_preview_shape_check');
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_preview_shape_check CHECK ('.
            "(preview_result IN ('ready', 'already_assessed') AND reason IS NULL) OR ".
            $review.
            "(preview_result = 'excluded' AND COALESCE(reason, '') IN ({$excluded})) OR ".
            "(preview_result = 'blocked' AND COALESCE(reason, '') IN ('no_structure', 'head_inactive', 'account_invalid')))"
        );
    }

    private function executionShape(string $failures): void
    {
        DB::statement('ALTER TABLE fee_assessment_run_items DROP CONSTRAINT fee_assessment_run_items_execution_shape_check');
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_execution_shape_check CHECK ('.
            "(execution_status IS NULL OR preview_result = 'ready') AND ".
            "(execution_status IS NULL OR execution_status IN ('pending', 'succeeded', 'skipped_already_assessed', 'failed')) AND ".
            "((COALESCE(execution_status, '') = 'succeeded') = (fee_assessment_id IS NOT NULL)) AND ".
            "((COALESCE(execution_status, '') = 'failed') = (failure_reason IS NOT NULL)) AND ".
            "(failure_reason IS NULL OR failure_reason IN ({$failures})) AND ".
            "((COALESCE(execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed')) = (executed_at IS NOT NULL)))"
        );
    }

    /**
     * The FEE.2 phase guard, with (UAT-C6) or without the overlap rules:
     * `overlap_charge_ids` is provenance (immutable); before execution the
     * only changes are a staff exclusion of a ready item (FEE.2) or one
     * recorded overlap decision of a review item; while executing, every
     * preview fact -- including the decision -- is fixed.
     */
    private function guard(bool $withOverlap): void
    {
        $provenance = $withOverlap ? "\n                    OR NEW.overlap_charge_ids IS DISTINCT FROM OLD.overlap_charge_ids" : '';
        $fixedWhileExecuting = $withOverlap ? "\n                        OR NEW.overlap_decision IS DISTINCT FROM OLD.overlap_decision\n                        OR NEW.overlap_decided_by_user_id IS DISTINCT FROM OLD.overlap_decided_by_user_id\n                        OR NEW.overlap_decided_at IS DISTINCT FROM OLD.overlap_decided_at" : '';
        $beforeExecution = $withOverlap
            ? <<<'SQL'
                    -- FEE.2: only a staff exclusion of a ready item (no decision change);
                    -- UAT-C6: or one overlap decision of a review item, or an
                    -- "include anyway" item later excluded after all.
                    -- COALESCE: a NULL comparison must never read as "allowed".
                    IF NEW.execution_status IS NOT NULL
                       OR NOT (
                           (OLD.preview_result = 'ready' AND NEW.preview_result = 'excluded'
                               AND COALESCE(NEW.reason, '') = 'staff_excluded'
                               AND NEW.overlap_decision IS NOT DISTINCT FROM OLD.overlap_decision)
                           OR (OLD.preview_result = 'review' AND OLD.overlap_decision IS NULL
                               AND ((NEW.preview_result = 'ready' AND COALESCE(NEW.overlap_decision, '') = 'include')
                                    OR (NEW.preview_result = 'excluded' AND COALESCE(NEW.reason, '') = 'overlap_excluded'
                                        AND COALESCE(NEW.overlap_decision, '') = 'exclude')))
                           OR (OLD.preview_result = 'ready' AND COALESCE(OLD.overlap_decision, '') = 'include'
                               AND NEW.preview_result = 'excluded' AND COALESCE(NEW.reason, '') = 'overlap_excluded'
                               AND COALESCE(NEW.overlap_decision, '') = 'exclude')
                       ) THEN
                        RAISE EXCEPTION 'only a staff exclusion of a ready item, or an overlap decision on a review item, may change before execution'
                            USING ERRCODE = 'check_violation';
                    END IF;
            SQL
            : <<<'SQL'
                    -- Only a staff exclusion of a ready item.
                    -- COALESCE: a NULL comparison must never read as "allowed".
                    IF NEW.execution_status IS NOT NULL
                       OR NOT (OLD.preview_result = 'ready' AND NEW.preview_result = 'excluded'
                               AND COALESCE(NEW.reason, '') = 'staff_excluded') THEN
                        RAISE EXCEPTION 'only a ready item can be excluded by staff before execution'
                            USING ERRCODE = 'check_violation';
                    END IF;
            SQL;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fees_guard_fee_assessment_run_item() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS \$\$
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
                    OR NEW.currency IS DISTINCT FROM OLD.currency{$provenance}
                THEN
                    RAISE EXCEPTION 'assessment run item % provenance is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF run_status IN ('draft', 'previewed') THEN
            {$beforeExecution}

                    RETURN NEW;
                END IF;

                IF run_status = 'executing' THEN
                    IF NEW.preview_result IS DISTINCT FROM OLD.preview_result
                        OR NEW.reason IS DISTINCT FROM OLD.reason
                        OR NEW.excluded_by_user_id IS DISTINCT FROM OLD.excluded_by_user_id
                        OR NEW.excluded_at IS DISTINCT FROM OLD.excluded_at{$fixedWhileExecuting}
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
            \$\$;
        SQL);
    }
};
