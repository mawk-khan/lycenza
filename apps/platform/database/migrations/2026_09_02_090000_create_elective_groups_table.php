<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1F.1 (docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md
     * §8) -- AcademicStructure-owned reference data: a named group of
     * mutually-exclusive `SubjectOffering`s within one AcademicYear/
     * Campus/GradeLevel context (e.g. "French OR Spanish"). Membership
     * is a single nullable FK on `subject_offerings.elective_group_id`
     * (§12/§13 of this migration set), not a pivot table -- an Offering
     * belongs to at most one ElectiveGroup.
     *
     * No `status` column (§8/§17 of the architecture doc, reaffirmed in
     * §26 of the 1F.0B pass) -- no repository evidence of an
     * independent group lifecycle; delete safety comes entirely from
     * restrict-on-delete FKs below plus §12's restrict-on-delete from
     * `subject_offerings`.
     *
     * Two composite unique constraints, both consumed by later
     * migrations in this set, not speculative (CLAUDE.md rule 2):
     * - `(id, school_id)` -- the standard 2-column composite-FK target
     *   every tenant-owned table exposes for its children; consumed by
     *   `student_subject_enrollments.elective_group_id`'s own FK.
     * - `(id, school_id, academic_year_id, campus_id, grade_level_id)`
     *   ("elective_groups_context_unique") -- consumed by
     *   `subject_offerings`' composite FK, making a cross-context
     *   group assignment (wrong AcademicYear/Campus/GradeLevel)
     *   structurally impossible rather than merely application-checked.
     *
     * `code` uniqueness follows the exact Subject/GradeLevel precedent
     * (App\Support\NormalizesCode, CLAUDE.md rule 74) -- scoped to
     * `(school_id, academic_year_id, campus_id, grade_level_id, code)`,
     * never platform-wide or School-wide. `name` is a display label
     * only, deliberately not unique.
     */
    public function up(): void
    {
        Schema::create('elective_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->string('name');
            $table->string('code');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(
                ['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'elective_groups_context_unique',
            );
            $table->unique(
                ['school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'code'],
                'elective_groups_code_unique',
            );
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();
        });

        TenantRls::enable('elective_groups');
    }

    public function down(): void
    {
        TenantRls::disable('elective_groups');
        Schema::dropIfExists('elective_groups');
    }
};
