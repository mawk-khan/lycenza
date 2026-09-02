<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4A (Examination Foundation): one named assessment WINDOW
     * that a School holds within one AcademicYear -- "Mid-Term
     * Examination 2026-27, 10 to 20 September". The first Examinations
     * fact, and the anchor every later Examinations fact hangs from.
     *
     * A WINDOW / CONTAINER, NOT A PAPER. One row is the examination
     * period a School announces; it is NOT one Subject's sitting. The
     * per-Subject entity (a future `ExaminationPaper`, Phase 0H.4B, its
     * own architecture gate) is what will carry SubjectOffering, a
     * per-paper date/time and max marks. This distinction is the single
     * most important thing to preserve about this table: "Mid-Term runs
     * 10-20 September" is a complete, durable, useful statement a School
     * publishes BEFORE any datesheet exists.
     *
     * Every write goes through
     * `App\Domain\Examinations\Application\ExaminationService` -- never
     * written directly anywhere else (see that class's docblock and
     * Tests\Feature\Examinations\ExaminationArchitectureGuardTest).
     *
     * EXACTLY TWO PARENTS: School and AcademicYear. Campus and
     * GradeLevel are deliberately absent -- a multi-campus School
     * normally holds one Mid-Term across campuses and across grades, and
     * per-campus/per-grade variation is a PAPER concern (a paper pins a
     * SubjectOffering, which already pins AcademicYear/Campus/
     * GradeLevel/Subject). Adding them here would force a separate
     * Examination row per campus even when nothing differs. With a
     * single non-tenant parent there is no cross-parent pinning to do,
     * so CLAUDE.md rule 70's composite-context pattern does not arise --
     * the identical reasoning `syllabus_units` records.
     *
     * NO `academic_term_id`. "Midterm Examination" is a NAME, not a term
     * reference; naming an examination after a term creates no data
     * dependency. A School that does not model AcademicTerms must still
     * be able to schedule examinations, and no invariant here needs one:
     * date validity is checked against the AcademicYear, which is the
     * AcademicTerm's own parent anyway. AcademicTerm additionally still
     * has no lifecycle status and no consuming domain. A nullable
     * `(academic_term_id, school_id)` reference is a purely additive
     * future path once a real invariant appears (term-wise report cards;
     * "term closed => marks frozen" -- the latter also needing the
     * lifecycle status AcademicTerm lacks).
     *
     * FUTURE DATES ARE PERMITTED AND EXPECTED -- deliberately the
     * OPPOSITE of `curriculum_deliveries`, and this is not an oversight.
     * A delivery records what HAS happened; an examination is SCHEDULED
     * AHEAD. `academic_years` and `academic_terms` both routinely carry
     * wholly future ranges at creation and neither service applies a
     * not-future rule. Forbidding future dates would make this entity
     * useless.
     *
     * OVERLAPPING WINDOWS ARE PERMITTED -- also deliberate. No business
     * invariant forbids a School running "Grade 10 Board Prep" and
     * "Grade 6 Unit Test" in overlapping weeks. `academic_terms` forbids
     * overlap because terms PARTITION a year by definition; examinations
     * partition nothing. This is precisely why this table needs no
     * `TenantLock`, no advisory lock, no exclusion constraint and no
     * concurrency test: there is no multi-row invariant at all.
     *
     * The code index is UNCONDITIONAL -- deliberately NOT scoped
     * `WHERE status = 'active'`. An inactive Examination continues to
     * reserve its code, which is exactly what makes reinstatement
     * conflict-free and is why this entity needs no activate/deactivate
     * command (`status` moves through the ordinary PATCH). Same
     * reasoning, same shape, as `syllabus_units_offering_code_ci_unique`.
     *
     * NO PERSONAL DATA, and that is what keeps this row Confidential
     * rather than Sensitive (docs/security/DATA-CLASSIFICATION.md):
     * there is no `student_id`, no enrollment reference, no `teacher_id`/
     * `employee_id`/`user_id` and no invigilator column, so unlike a
     * `timetable_entries` or `attendance_records` row this one
     * identifies nobody. Attaching an invigilator, a Student mark or an
     * individual result would each elevate THAT entity to Sensitive.
     *
     * DELIBERATELY ABSENT beyond that: `subject_id`, `subject_offering_id`,
     * `section_id`, `syllabus_unit_id`, `curriculum_delivery_id`,
     * `campus_id`, `grade_level_id`, `academic_term_id`, `sequence`,
     * `description`/`instructions`/any free text (an unbounded prose
     * column is an audit-leakage and classification hazard -- it could
     * carry Student-specific commentary -- and `syllabus_units` rejected
     * `description` on the same grounds), and every paper/max-marks/
     * score/grade-scale/result/publication/report-card/transcript/
     * attachment field. Those belong to Phase 0H.4B and later, to
     * Examinations' own later checkpoints, or to Documents.
     */
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');

            $table->string('code', 64);
            $table->string('name');

            // School calendar dates, not UTC instants. Future dates are
            // expected -- see the docblock.
            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            // Retained so a future ExaminationPaper row (Phase 0H.4B)
            // can reference this table tenant-pinned, exactly as
            // syllabus_units did for curriculum_deliveries.
            $table->unique(['id', 'school_id']);

            $table->index(['school_id', 'status']);
            $table->index(['academic_year_id']);

            // Tenant-pinned parent reference. RESTRICT: an AcademicYear
            // carrying examinations must never be hard-deleted out from
            // under them (that year has no delete route today -- this is
            // defensive completeness, matching every other Academic
            // Structure reference).
            $table->foreign(['academic_year_id', 'school_id'], 'examinations_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE examinations ADD CONSTRAINT examinations_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE examinations ADD CONSTRAINT examinations_date_order_check '.
            'CHECK (ends_on >= starts_on)'
        );

        // Case-insensitive code uniqueness WITHIN one AcademicYear --
        // see this migration's docblock. The same normalized code
        // legitimately recurs in a different year ("MID1" every year is
        // a distinct row). An expression index rather than a plain
        // unique index because `App\Support\NormalizesCode` uppercases
        // at the Eloquent mutator layer, and a raw SQL insert bypassing
        // that mutator would not collide with a plain-string index.
        DB::statement(
            'CREATE UNIQUE INDEX examinations_year_code_ci_unique '.
            'ON examinations (school_id, academic_year_id, upper(code))'
        );

        TenantRls::enable('examinations');
    }

    public function down(): void
    {
        TenantRls::disable('examinations');
        Schema::dropIfExists('examinations');
    }
};
