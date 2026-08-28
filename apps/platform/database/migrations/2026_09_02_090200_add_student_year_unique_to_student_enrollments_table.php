<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1F.1 (architecture doc §11B) -- a small, purely additive
     * supporting index on the already-accepted `student_enrollments`
     * table, mirroring the exact precedent already established by
     * `2026_08_24_090000_add_student_composite_unique_to_student_enrollments_table.php`
     * (Phase 1B.7A's `unique(['id', 'student_id'])`).
     *
     * `id` alone is already globally unique (the primary key), so
     * `unique(['id', 'school_id', 'student_id', 'academic_year_id'])`
     * is trivially satisfied by every existing row and changes no
     * existing behavior, constraint, or query plan for any prior
     * StudentEnrollment code. Its sole purpose is enabling the new
     * `student_subject_enrollments.student_enrollment_id` placement-
     * anchor composite FK (this migration set's next file) to
     * structurally guarantee "this row's claimed StudentEnrollment
     * really belongs to this row's claimed Student and AcademicYear" --
     * closing the cross-Student/cross-AcademicYear bypass the
     * architecture doc's §13 counterexample describes.
     *
     * This migration touches ONLY the index; it does not add, remove,
     * or modify any column, and does not alter `student_enrollments`'
     * pre-existing RLS policy, lifecycle semantics, or any other
     * constraint.
     */
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->unique(
                ['id', 'school_id', 'student_id', 'academic_year_id'],
                'student_enrollments_id_school_student_year_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('student_enrollments_id_school_student_year_unique');
        });
    }
};
