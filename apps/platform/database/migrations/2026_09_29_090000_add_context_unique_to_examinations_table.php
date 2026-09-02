<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4B (ExaminationPaper / Scheduling): additive, non-destructive
     * -- adds a SECOND composite unique key to `examinations`, mirroring
     * `subject_offerings_context_unique`'s own precedent exactly
     * (`add_context_unique_to_subject_offerings_table` migration).
     * `examinations` already carries `unique(['id', 'school_id'])` (the
     * standard 2-column composite-FK target) from its own creation
     * migration -- this adds the richer 3-column key so the new
     * `examination_papers` table can declare a composite FK that
     * structurally pins an ExaminationPaper's `examination_id` to the SAME
     * `academic_year_id` it also declares for its `subject_offering_id`
     * (via `subject_offerings_context_unique`), rather than merely
     * trusting the application layer to keep them consistent (CLAUDE.md
     * rule 70). This is what guarantees
     * `Examination.academic_year_id == SubjectOffering.academic_year_id`
     * even through a raw SQL insert.
     *
     * Does not touch `examinations_id_school_id_unique`,
     * `examinations_academic_year_fk`, `examinations_status_check`,
     * `examinations_date_order_check`, `examinations_year_code_ci_unique`
     * or RLS -- Phase 0H.4A's design is otherwise untouched.
     */
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->unique(['id', 'school_id', 'academic_year_id'], 'examinations_context_unique');
        });
    }

    public function down(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->dropUnique('examinations_context_unique');
        });
    }
};
