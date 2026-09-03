<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4B -- one SubjectOffering assessed within one Examination,
     * with its scheduled sitting (date and time range) and maximum
     * obtainable marks. An Examination child and an Offering-wide
     * scheduling fact -- NOT a physical uploaded question-paper file, NOT
     * Section-specific, NOT a Student attempt, NOT a mark/result, and NOT
     * an LMS assignment. Every write goes through
     * `App\Domain\Examinations\Application\ExaminationPaperService` --
     * never written directly anywhere else (see that class's docblock and
     * Tests\Feature\Examinations\ExaminationPaperArchitectureGuardTest).
     *
     * EXACTLY ONE Paper per (school_id, examination_id,
     * subject_offering_id) -- `examination_papers_examination_offering_unique`,
     * UNCONDITIONAL (never scoped `WHERE status = 'active'`), so an
     * inactive Paper continues to reserve the pair and ordinary
     * reactivation is conflict-free. No Paper 1/Paper 2, no
     * theory/practical component and no sequence -- multi-component
     * papers are a future architecture/migration if ever required.
     *
     * OFFERING-WIDE, NOT SECTION-SPECIFIC: there is deliberately no
     * `section_id`. Both required AND elective SubjectOfferings are
     * supported identically; `is_required` is never inspected here.
     *
     * CROSS-PARENT INTEGRITY. `academic_year_id`, `campus_id` and
     * `grade_level_id` are internal, server-derived integrity pins,
     * never authored by a client and never exposed in the public API
     * representation:
     *
     *   - `examination_papers_examination_fk` pins
     *     (examination_id, school_id, academic_year_id) to
     *     `examinations(id, school_id, academic_year_id)` -- see the
     *     companion `add_context_unique_to_examinations_table` migration,
     *     which this table's creation migration structurally depends on
     *     (must run first).
     *   - `examination_papers_subject_offering_fk` pins
     *     (subject_offering_id, school_id, academic_year_id, campus_id,
     *     grade_level_id) to `subject_offerings(id, school_id,
     *     academic_year_id, campus_id, grade_level_id)` (already
     *     available via `subject_offerings_context_unique`).
     *
     * Both FKs share the SAME stored `academic_year_id` column, which is
     * what structurally guarantees
     * `Examination.academic_year_id == SubjectOffering.academic_year_id`
     * even through a raw SQL insert bypassing the service entirely
     * (CLAUDE.md rule 70; proven in
     * Tests\Feature\Postgres\ExaminationPapersRlsIsolationTest).
     *
     * `unique(['id', 'school_id'])` is retained for a future StudentMark
     * tenant-pinned reference, exactly as `examinations` and
     * `subject_offerings` already do for their own dependents.
     *
     * SCHOOL-LOCAL WALL-CLOCK TIME: `scheduled_on` (DATE),
     * `starts_at`/`ends_at` (TIME) -- never converted to or stored as
     * UTC. Same-day sittings only: `examination_papers_time_order_check`
     * enforces `ends_at > starts_at`; overnight sittings are unsupported
     * in v1.
     *
     * OVERLAPS ACROSS DIFFERENT OFFERINGS ARE PERMITTED -- deliberately
     * no overlap query, no roster intersection, no lock and no exclusion
     * constraint. The only duplicate prevention is the aggregate unique
     * constraint above.
     *
     * `max_marks` is `NUMERIC(6,2)`, never a float -- decimals are
     * supported, and `examination_papers_max_marks_check` requires a
     * strictly positive value. No `pass_marks`, no grade scale and no
     * scoring precision rule -- those belong to a later checkpoint.
     *
     * `status` is `active|inactive` only
     * (`examination_papers_status_check`) -- an ordinary field, not a
     * state machine. No DELETE route and no activate/deactivate command;
     * inactive is the withdrawal representation, exactly like
     * `examinations` itself.
     *
     * NO PERSONAL DATA -- no `student_id`, no enrollment reference, no
     * `teacher_id`/`employee_id`/`invigilator_id`, no `room_id`, no
     * `timetable_entry_id`. That is what keeps this row Confidential
     * rather than Sensitive (docs/security/DATA-CLASSIFICATION.md).
     *
     * DELIBERATELY ABSENT: `section_id`, `student_id`,
     * `student_enrollment_id`, `student_subject_enrollment_id`,
     * `teacher_id`, `employee_id`, `room_id`, `timetable_entry_id`,
     * `academic_term_id`, `syllabus_unit_id`, `curriculum_delivery_id`,
     * a duplicate Subject column, `code`/`title`/`component`/
     * `paper_number`, any physical document/file field, marks/scores,
     * grade scale, pass marks, result/publication data, and any free-text
     * description/instructions.
     */
    public function up(): void
    {
        Schema::create('examination_papers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            $table->uuid('examination_id');
            $table->uuid('subject_offering_id');

            // Internal integrity pins, server-derived, never client
            // authored and never exposed in the public representation.
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');

            // School-local wall-clock date/time -- not UTC instants.
            $table->date('scheduled_on');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->decimal('max_marks', 6, 2);

            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            // Retained for a future StudentMark tenant-pinned reference,
            // exactly as `examinations`/`subject_offerings` already do.
            $table->unique(['id', 'school_id']);

            // Exactly one Paper per Examination x SubjectOffering,
            // unconditional -- see the migration docblock.
            $table->unique(
                ['school_id', 'examination_id', 'subject_offering_id'],
                'examination_papers_examination_offering_unique',
            );

            $table->index(['examination_id']);
            $table->index(['subject_offering_id']);
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);
            $table->index(['school_id', 'status']);

            $table->foreign(
                ['examination_id', 'school_id', 'academic_year_id'],
                'examination_papers_examination_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id'])
                ->on('examinations')
                ->restrictOnDelete();

            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'examination_papers_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE examination_papers ADD CONSTRAINT examination_papers_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE examination_papers ADD CONSTRAINT examination_papers_time_order_check '.
            'CHECK (ends_at > starts_at)'
        );

        DB::statement(
            'ALTER TABLE examination_papers ADD CONSTRAINT examination_papers_max_marks_check '.
            'CHECK (max_marks > 0)'
        );

        TenantRls::enable('examination_papers');
    }

    public function down(): void
    {
        TenantRls::disable('examination_papers');
        Schema::dropIfExists('examination_papers');
    }
};
