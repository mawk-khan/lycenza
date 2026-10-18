<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.7D-0 R2 (ADR 0076 §36): an enrollment's School, Academic Year,
     * Campus and Grade Level must be its Section's. Until now
     * `student_enrollments` bound `campus_id` and `section_id` separately
     * (two `(…, school_id)` keys), so only `StudentEnrollmentService` kept
     * them consistent (ORG.7C finding R2, §35.7; ADR 0076 §11.7 correction).
     *
     * Additive: one composite FK onto the existing `sections_context_unique`
     * key -- the same shape `timetable_entries_section_fk`,
     * `attendance_sessions_section_fk` and `teaching_assignments_section_fk`
     * already use. Any write, including a raw UPDATE of a placement column,
     * must now keep the pair consistent, and a Section's Campus, Year or
     * Grade can no longer change under an enrollment (NO ACTION on update).
     *
     * This proves relational consistency only. It never says which
     * enrollment is a Student's CURRENT one -- that stays an application
     * concept (§35.2: the active enrollment in the School's active year).
     * `students` gains no Campus.
     *
     * Adding the FK validates every existing row: a mismatched row fails the
     * migration as a whole and nothing is rewritten. ORG.7D-0 preflight found
     * zero such rows in every local database.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_section_context_fk
            FOREIGN KEY (section_id, school_id, academic_year_id, campus_id, grade_level_id)
            REFERENCES sections (id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE student_enrollments DROP CONSTRAINT student_enrollments_section_context_fk');
    }
};
