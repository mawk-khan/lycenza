<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H (Timetable foundation): additive, non-destructive --
     * adds a SECOND composite unique key to `subject_offerings`,
     * mirroring `elective_groups`' own `elective_groups_context_unique`
     * precedent exactly (`create_elective_groups_table` migration).
     * `subject_offerings` already carries `unique(['id', 'school_id'])`
     * (the standard 2-column composite-FK target) from its own
     * creation migration -- this adds the richer 5-column key so a
     * LATER migration (`timetable_entries`) can declare a composite FK
     * that structurally pins a TimetableEntry's `subject_offering_id`
     * to the SAME AcademicYear/Campus/GradeLevel context it also
     * declares for its Section, rather than merely trusting the
     * application layer to keep them consistent (CLAUDE.md rule 70).
     */
    public function up(): void
    {
        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->unique(
                ['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'subject_offerings_context_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->dropUnique('subject_offerings_context_unique');
        });
    }
};
