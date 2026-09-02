<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.3B (Curriculum Delivery): the record that one Section
     * has covered one SyllabusUnit -- when that Section began it, and
     * when, if yet, it finished. The second concrete Academics fact,
     * and the counterpart to Phase 0H.3A's `syllabus_units`: a
     * SyllabusUnit is the catalogue of EXPECTED content, a
     * CurriculumDelivery is ACTUAL instructional coverage by a cohort.
     *
     * Every write goes through
     * `App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService`
     * -- never written directly anywhere else (see that class's
     * docblock and Tests\Feature\CurriculumDelivery\
     * CurriculumDeliveryArchitectureGuardTest).
     *
     * SECTION-SPECIFIC, unlike the Offering-wide syllabus catalogue.
     * `syllabus_units` deliberately has no `section_id` because "the
     * syllabus is Offering-wide -- every Section taking an Offering is
     * expected to cover the same content. Per-Section variation is a
     * DELIVERY concern" (docs/modules/ACADEMICS.md §4). This is that
     * delivery concern: two Sections of the same GradeLevel genuinely
     * progress through the same syllabus at different rates, and
     * "has 10-A covered Unit 3?" is the question this table exists to
     * answer.
     *
     * REQUIRED SubjectOfferings ONLY in v1, enforced by the service
     * (`RequiredSubjectOfferingOnlyException`), exactly as
     * `TimetableScheduleService` already restricts scheduling. An
     * elective Offering has no Section-wide cohort at all: a Student
     * opts into an elective individually via
     * `student_subject_enrollments` (which carries NO `section_id`),
     * so a Section-keyed delivery row is semantically wrong for one.
     * This deliberately makes delivery narrower than the catalogue,
     * which supports both -- see docs/modules/ACADEMICS.md.
     *
     * THREE COMPOSITE FOREIGN KEYS ARE THE CENTRAL CORRECTNESS
     * INVARIANT of this checkpoint. Tenant isolation alone is NOT
     * sufficient consistency here: without them, a same-School Section
     * teaching Mathematics could record delivery against a Science
     * SyllabusUnit. Composed, they make that structurally impossible
     * even through a raw SQL INSERT that bypasses Eloquent entirely:
     *
     *   1. curriculum_deliveries_section_fk pins the Section to this
     *      row's AcademicYear/Campus/GradeLevel;
     *   2. curriculum_deliveries_subject_offering_fk pins the
     *      SubjectOffering to the SAME three context columns;
     *   3. curriculum_deliveries_syllabus_unit_fk pins the SyllabusUnit
     *      to THAT EXACT SubjectOffering.
     *
     * So the Unit's Offering is context-identical to the Section's
     * context, transitively. FKs 1 and 2 are the identical shape
     * `timetable_entries` and `attendance_sessions` already use
     * (CLAUDE.md rule 70); FK 3 consumes the additive
     * `syllabus_units_offering_context_unique` key added by this
     * migration set's immediately preceding sibling.
     *
     * `academic_year_id`/`campus_id`/`grade_level_id`/
     * `subject_offering_id` are therefore INTEGRITY PINS, not
     * convenience denormalization -- each is a composite-FK component
     * and none is readable client input (the service derives all four
     * server-side from the resolved parents). `syllabus_units` itself
     * correctly denormalizes none of them, because it has only ONE
     * parent and the pattern exists to pin TWO parents to the SAME
     * context; this table has three parents, so the pattern applies.
     *
     * EVERY composite-FK component is NOT NULL, for the reason
     * `attendance_sessions`' docblock records: under PostgreSQL's
     * default MATCH SIMPLE a foreign-key check is skipped entirely
     * whenever any referencing column is NULL -- a bypass this
     * repository has already proven empirically and had to install a
     * trigger to close (Phase 1F.1). A nullable `section_id` meaning
     * "Offering-wide delivery" was considered and rejected for exactly
     * that reason.
     *
     * TWO STORED STATES ONLY: `in_progress` and `completed`.
     * `not_started` is deliberately NEVER stored -- the ABSENCE of a
     * row already means not started, and pre-seeding one row per
     * Section x SyllabusUnit would write a fact nobody asserted. Any
     * consumer needing "all units with their state" LEFT JOINs from
     * `syllabus_units`; it must never count rows here. `completed_on`
     * and `status` are kept honest by a biconditional CHECK rather than
     * by application discipline.
     *
     * NO teacher identity, and that is what keeps this row Confidential
     * rather than Sensitive (docs/security/DATA-CLASSIFICATION.md):
     * there is no `teacher_id`, `employee_id` or `user_id` column, so
     * unlike a `timetable_entries`/`attendance_sessions` row this one
     * identifies no person at all. A Section is an organisational
     * cohort, not an individual. Recording who taught a unit needs the
     * platform's first ownership-based authorization model, which does
     * not exist yet (docs/roadmap/MASTER-ROADMAP.md).
     *
     * DELIBERATELY ABSENT beyond that: `timetable_entry_id` (no
     * Timetable dependency -- delivery must stay recordable by a School
     * with no complete weekly timetable, and coverage is not an
     * occurrence of a scheduled class), any Attendance or
     * StudentEnrollment/StudentSubjectEnrollment reference (Student
     * presence does not affect whether the cohort covered the unit),
     * `academic_term_id` (AcademicTerm still has no status, lifecycle
     * or consumers), `sequence` (`syllabus_units.sequence` already
     * carries teaching order), and every free-text/lesson/LMS/
     * Examinations field -- notes, description, objectives, resources,
     * homework, lesson counts, periods used, hours taught, attachments,
     * Documents, assignments, marks, grades and assessments. Lesson
     * Planning, LMS (Phase 0I) and Examinations own those; Academics
     * must never reference the latter two (docs/modules/ACADEMICS.md).
     */
    public function up(): void
    {
        Schema::create('curriculum_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            // The fact itself: this cohort covered this unit.
            $table->uuid('section_id');
            $table->uuid('syllabus_unit_id');

            // Integrity pins -- all server-derived, never client input.
            $table->uuid('subject_offering_id');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');

            // School-local calendar dates, not UTC instants.
            $table->date('started_on');
            $table->date('completed_on')->nullable();

            $table->string('status')->default('in_progress'); // in_progress|completed -- see CHECK below
            $table->timestamps();

            // Retained so a future child row (e.g. a Lesson Planning
            // record) can reference this one tenant-pinned, exactly as
            // syllabus_units did for this table.
            $table->unique(['id', 'school_id']);

            // ONE delivery row per cohort per unit. This is the
            // authoritative duplicate guard -- the service translates
            // only THIS constraint's violation into a clean 422, and a
            // genuine race that reaches PostgreSQL is rejected here,
            // never by an application check-then-insert.
            $table->unique(
                ['school_id', 'section_id', 'syllabus_unit_id'],
                'curriculum_deliveries_section_unit_unique',
            );

            $table->index(['school_id', 'status']);
            $table->index(['section_id']);
            $table->index(['syllabus_unit_id']);
            $table->index(['subject_offering_id']);
            $table->index(['academic_year_id']);

            // (1) Section pinned to this row's context.
            $table->foreign(
                ['section_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'curriculum_deliveries_section_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('sections')
                ->restrictOnDelete();

            // (2) SubjectOffering pinned to the SAME context, so a
            // Section and an Offering from different years/campuses/
            // grades can never appear on one row.
            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'curriculum_deliveries_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();

            // (3) The SyllabusUnit must belong to THAT EXACT Offering.
            // Consumes syllabus_units_offering_context_unique. This is
            // the FK that closes the Mathematics-Section/Science-Unit
            // hole; the other two cannot do it alone.
            $table->foreign(
                ['syllabus_unit_id', 'school_id', 'subject_offering_id'],
                'curriculum_deliveries_syllabus_unit_fk',
            )
                ->references(['id', 'school_id', 'subject_offering_id'])
                ->on('syllabus_units')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE curriculum_deliveries ADD CONSTRAINT curriculum_deliveries_status_check '.
            "CHECK (status IN ('in_progress', 'completed'))"
        );

        // The biconditional that makes `status` and `completed_on`
        // incapable of disagreeing: a completed delivery always has a
        // completion date, and an in-progress one never does. This is
        // what allows `status` to exist alongside the date without
        // being unsafe redundancy.
        DB::statement(
            'ALTER TABLE curriculum_deliveries ADD CONSTRAINT curriculum_deliveries_completion_check '.
            "CHECK ((status = 'completed') = (completed_on IS NOT NULL))"
        );

        DB::statement(
            'ALTER TABLE curriculum_deliveries ADD CONSTRAINT curriculum_deliveries_date_order_check '.
            'CHECK (completed_on IS NULL OR completed_on >= started_on)'
        );

        TenantRls::enable('curriculum_deliveries');
    }

    public function down(): void
    {
        TenantRls::disable('curriculum_deliveries');
        Schema::dropIfExists('curriculum_deliveries');
    }
};
