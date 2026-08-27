<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1F.1 (architecture doc §12-14) -- the configuration fact
     * that a `SubjectOffering` participates in an `ElectiveGroup`.
     * Nullable: NULL means "not a member of any mutual-exclusivity
     * group" (unchanged, ungrouped-elective behavior, including every
     * existing offering -- no semantic backfill performed here, §18A).
     *
     * The composite FK below ties `elective_group_id` to the SAME
     * AcademicYear/Campus/GradeLevel context as this Offering row --
     * a structural (not merely application-checked) guarantee that a
     * Group can never be assigned across a mismatched academic
     * context. This is a DIFFERENT guarantee from the Phase 1F.0B
     * snapshot-equality trigger installed on
     * `student_subject_enrollments` later in this migration set: this
     * FK protects `subject_offerings` -> `elective_groups` configuration
     * integrity; that trigger protects
     * `student_subject_enrollments` -> `subject_offerings` snapshot
     * integrity. Neither subsumes the other.
     *
     * The CHECK constraint enforces the accepted required/elective rule
     * (architecture doc §9, unchanged from Phase 1F.0): a REQUIRED
     * offering can never carry a group assignment. No trigger needed --
     * this is a same-row, single-table invariant.
     */
    public function up(): void
    {
        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->uuid('elective_group_id')->nullable()->after('subject_id');
            $table->index(['elective_group_id']);

            $table->foreign(
                ['elective_group_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'subject_offerings_elective_group_context_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('elective_groups')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE subject_offerings ADD CONSTRAINT subject_offerings_required_group_check '.
            'CHECK (elective_group_id IS NULL OR is_required = false)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subject_offerings DROP CONSTRAINT IF EXISTS subject_offerings_required_group_check');

        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->dropForeign('subject_offerings_elective_group_context_fk');
            $table->dropColumn('elective_group_id');
        });
    }
};
