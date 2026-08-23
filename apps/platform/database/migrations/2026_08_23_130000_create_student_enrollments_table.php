<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B.1: a Student's time-varying academic placement, kept
     * permanently separate from Student identity (Phase 1A) -- see
     * docs/modules/STUDENT-ENROLLMENT.md ("Identity vs enrollment
     * boundary"). A Student may accumulate many StudentEnrollment rows
     * over their School lifetime (promotion, section movement, campus
     * movement, year rollover, withdrawal, transfer, re-enrollment);
     * none of them are ever mutated in place to represent a later year
     * -- the same historical-safety rule Section already established
     * for itself (Phase 0D section 28).
     *
     * `academic_year_id`, `campus_id`, and `grade_level_id` are
     * deliberately denormalized here even though `section_id` alone
     * already implies all three (a Section belongs to exactly one
     * AcademicYear/Campus/GradeLevel) -- this is required so that:
     *   (a) the "one active Enrollment per Student per AcademicYear"
     *       invariant below can be a PostgreSQL partial unique index
     *       (a partial index cannot reference a joined table's column);
     *   (b) each parent reference gets its own composite-FK protection
     *       against `school_id`, matching the Section/SubjectOffering
     *       precedent exactly (never rely on RLS alone to prevent a
     *       cross-School reference at INSERT time).
     * Consistency between `section_id` and the other three columns is
     * NOT a database constraint (PostgreSQL cannot cheaply express
     * "these columns must equal another row's columns" without a
     * trigger) -- it is guaranteed by construction because
     * App\Domain\Students\Application\StudentEnrollmentService (Phase
     * 1B.4) is the only sanctioned write path and always derives
     * academic_year_id/campus_id/grade_level_id FROM the caller-chosen
     * Section server-side, exactly like Student/Guardian identity
     * fields are never accepted as independent, untrusted input (rule
     * 19's principle applied here). Phase 1B.1 ships only the schema
     * and model -- the enforcing service lands in Phase 1B.4.
     *
     * `section_id` is NOT NULL in this first slice -- every Enrollment
     * has a concrete Section, matching this checkpoint's own suggested
     * Roll Number uniqueness scope (school + academic_year + section).
     * A future "sectionless small School" checkpoint can relax this if
     * a real product need emerges; it is not invented speculatively
     * here (CLAUDE.md rule 2).
     *
     * `roll_number` is an Enrollment attribute, never Student identity
     * -- it may legitimately repeat across different Sections/years for
     * different Students, so uniqueness is scoped to
     * (school_id, academic_year_id, section_id, roll_number), never
     * global and never School-wide.
     *
     * Two invariants are PostgreSQL-enforced, not application-check-
     * then-insert, following the `academic_years_one_active_per_school`
     * precedent exactly:
     * - A partial unique index on (student_id, academic_year_id) WHERE
     *   status = 'active' -- at most one active Enrollment per Student
     *   per AcademicYear (this checkpoint's chosen invariant; see
     *   docs/modules/STUDENT-ENROLLMENT.md "Active enrollment
     *   invariant" for why AcademicYear-scoped was chosen over
     *   Campus+AcademicYear-scoped).
     * - A CHECK constraint that `ends_on >= starts_on` when `ends_on`
     *   is present (same-day withdrawal is valid, unlike an Academic
     *   Year which needs a genuine multi-day duration).
     *
     * Delete behavior mirrors student_guardian_relationships: the
     * composite FK to `students` cascades (a hard-deleted Student
     * removes only its own Enrollment history; Students have no delete
     * endpoint today, this is defensive completeness). The composite
     * FKs to academic_years/campuses/grade_levels/sections all
     * restrict-on-delete, matching Section's own FK behavior toward
     * those same parents -- none of those reference tables expose a
     * delete endpoint either (CLAUDE.md rule 73), so this is likewise
     * defensive-only.
     */
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('section_id');
            $table->string('roll_number');
            $table->string('status')->default('active'); // active|completed|withdrawn|transferred|cancelled
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from future Enrollment-scoped children (Attendance, Exams, Fees)
            $table->unique(['school_id', 'academic_year_id', 'section_id', 'roll_number']);
            $table->index(['school_id', 'student_id']);
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);
            $table->index(['section_id']);
            $table->index(['status']);

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['section_id', 'school_id'])
                ->references(['id', 'school_id'])->on('sections')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_date_range_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement(
            'CREATE UNIQUE INDEX student_enrollments_one_active_per_student_year '.
            "ON student_enrollments (student_id, academic_year_id) WHERE status = 'active'"
        );

        TenantRls::enable('student_enrollments');
    }

    public function down(): void
    {
        TenantRls::disable('student_enrollments');
        Schema::dropIfExists('student_enrollments');
    }
};
