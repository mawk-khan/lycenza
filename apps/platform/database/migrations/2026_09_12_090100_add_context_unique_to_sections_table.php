<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H (Timetable foundation): additive, non-destructive --
     * same reasoning and shape as this migration set's
     * `add_context_unique_to_subject_offerings_table` sibling, applied
     * to `sections`. Consumed by `timetable_entries.section_id`'s own
     * composite FK so a TimetableEntry's Section is structurally
     * pinned to the exact same AcademicYear/Campus/GradeLevel context
     * as its SubjectOffering (CLAUDE.md rule 70).
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unique(
                ['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'sections_context_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique('sections_context_unique');
        });
    }
};
