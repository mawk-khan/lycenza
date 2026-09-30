<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FEE.3 (ADR 0062 §14.4, owner decision G1): a standing concession is
     * applied inside the assessment item's transaction, so two new closed
     * failure reasons join the FEE.2 catalogue. Either one rolls the whole
     * item savepoint back (no charge, no partial concession):
     *
     * - `concession_exceeds_outstanding`: the matching standing
     *   concessions' computed value exceeds what remains of the new charge
     *   (refused, never reduced);
     * - `concession_account_invalid`: a concession applies but the School's
     *   concession account is missing, inactive or not `expense` (F2, fail
     *   closed).
     */
    private const BASE = "'structure_not_active', 'structure_not_resolved', 'enrollment_not_qualifying', 'student_inactive', 'optional_not_selected', 'head_inactive', 'account_invalid', 'error'";

    public function up(): void
    {
        $this->replace(self::BASE.", 'concession_exceeds_outstanding', 'concession_account_invalid'");
    }

    public function down(): void
    {
        // Irreversible only if a failed item already carries a FEE.3
        // reason; the re-added CHECK then refuses, which is the intended
        // fail-closed signal rather than silently rewriting history.
        $this->replace(self::BASE);
    }

    private function replace(string $reasons): void
    {
        DB::statement('ALTER TABLE fee_assessment_run_items DROP CONSTRAINT fee_assessment_run_items_execution_shape_check');
        DB::statement(
            'ALTER TABLE fee_assessment_run_items ADD CONSTRAINT fee_assessment_run_items_execution_shape_check CHECK ('.
            "(execution_status IS NULL OR preview_result = 'ready') AND ".
            "(execution_status IS NULL OR execution_status IN ('pending', 'succeeded', 'skipped_already_assessed', 'failed')) AND ".
            "((COALESCE(execution_status, '') = 'succeeded') = (fee_assessment_id IS NOT NULL)) AND ".
            "((COALESCE(execution_status, '') = 'failed') = (failure_reason IS NOT NULL)) AND ".
            "(failure_reason IS NULL OR failure_reason IN ({$reasons})) AND ".
            "((COALESCE(execution_status, '') IN ('succeeded', 'skipped_already_assessed', 'failed')) = (executed_at IS NOT NULL)))"
        );
    }
};
