<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.2 (Student Attendance foundation): a small, purely
     * additive supporting index on the already-accepted
     * `student_enrollments` table (Phase 1B.1), mirroring the exact
     * precedent already established TWICE on this same table --
     * `2026_08_24_090000_add_student_composite_unique_to_student_enrollments_table.php`
     * (Phase 1B.7A's `unique(['id', 'student_id'])`) and
     * `2026_09_02_090200_add_student_year_unique_to_student_enrollments_table.php`
     * (Phase 1F.1's `unique(['id','school_id','student_id','academic_year_id'])`).
     *
     * `id` alone is already globally unique (the primary key), so
     * `unique(['id','school_id','academic_year_id','campus_id','grade_level_id','section_id'])`
     * is trivially satisfied by every existing row and changes no
     * existing behavior, no existing constraint, and no existing query
     * plan for any prior StudentEnrollment code. Its SOLE purpose is
     * enabling the new `attendance_records` enrollment-context
     * composite FK (`attendance_records_enrollment_context_fk`) to
     * structurally guarantee "this attendance row's claimed
     * StudentEnrollment really belongs to this row's claimed
     * Section/AcademicYear/Campus/GradeLevel" -- the same class of
     * guarantee `unique(['id','school_id'])` already gives every
     * tenant-owned table for the School dimension, applied here to the
     * full academic-placement context.
     *
     * This matters specifically because `student_enrollments`' own
     * creation migration states plainly that consistency between
     * `section_id` and the denormalized `academic_year_id`/`campus_id`/
     * `grade_level_id` is NOT a database constraint -- it is guaranteed
     * by construction because App\Domain\Students\Application\StudentEnrollmentService
     * is the only sanctioned write path. Binding Attendance on the full
     * 6-tuple therefore ALSO makes Attendance fail closed against an
     * enrollment row whose own denormalized context is internally
     * inconsistent, rather than silently importing that inconsistency
     * into permanent Attendance history.
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
                ['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
                'student_enrollments_context_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('student_enrollments_context_unique');
        });
    }
};
