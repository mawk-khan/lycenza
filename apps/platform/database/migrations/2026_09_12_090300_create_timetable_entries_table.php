<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H (Timetable foundation): a single scheduled slot --
     * "SubjectOffering X is taught to Section Y by teacher (Employee) Z
     * in Room R during Period P on day-of-week D". Every write goes
     * through `App\Domain\Timetable\Application\TimetableScheduleService`
     * -- never written directly elsewhere.
     *
     * `academic_year_id`/`campus_id`/`grade_level_id` are carried on
     * this row (denormalized from the SubjectOffering that owns them,
     * CLAUDE.md rule 71's precedent already established for
     * `student_subject_enrollments`) so BOTH composite FKs below can
     * pin their respective parent to the SAME context, structurally --
     * a TimetableEntry can never reference a SubjectOffering and a
     * Section that belong to different AcademicYear/Campus/GradeLevel
     * combinations, even via a raw SQL insert bypassing
     * `TimetableScheduleService` entirely (CLAUDE.md rule 70; proven in
     * `Tests\Feature\Postgres\TimetableEntriesRlsIsolationTest`).
     *
     * `room_id` is nullable (a Period can legitimately have no assigned
     * Room, e.g. a games period) -- its composite FK and its own slot
     * uniqueness index both tolerate NULL correctly (a NULL FK column
     * is simply not checked; the partial unique index below is
     * additionally scoped `AND room_id IS NOT NULL` so any number of
     * roomless entries never collide with each other).
     *
     * No exclusion/overlap constraint against Period TIME ranges is
     * needed here (unlike `timetable_periods` itself) -- a
     * TimetableEntry references a Period by id, not by its own copy of
     * start/end time, so "same School + same teacher/Section/Room +
     * same day-of-week + same Period id" is an exact-match collision a
     * plain partial unique index expresses correctly.
     *
     * All THREE double-booking guards below are scoped
     * `WHERE status = 'active'` -- a deactivated entry (the row is kept
     * for historical/audit purposes, never deleted, CLAUDE.md rule 73's
     * reference-entity-lifecycle discipline extended to this
     * transactional-but-append-preferred table) frees its slot for a
     * new entry immediately, since the index no longer counts it.
     * Deterministic constraint names are required here specifically so
     * `App\Domain\Timetable\Application\TimetableScheduleService` can
     * translate a caught `QueryException` into the matching typed
     * domain exception by constraint name, mirroring
     * `App\Domain\Fees\Application\ChargeService::violatesConstraint()`'s
     * established pattern -- never leaking a raw database message to a
     * caller.
     */
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('subject_offering_id');
            $table->uuid('section_id');
            $table->uuid('teacher_id');
            $table->uuid('room_id')->nullable();
            $table->uuid('period_id');
            $table->smallInteger('day_of_week'); // 1 (Monday) .. 7 (Sunday) -- see CHECK below
            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);
            $table->index(['section_id']);
            $table->index(['teacher_id']);
            $table->index(['room_id']);
            $table->index(['period_id']);

            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'timetable_entries_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();

            $table->foreign(
                ['section_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'timetable_entries_section_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('sections')
                ->restrictOnDelete();

            $table->foreign(['teacher_id', 'school_id'], 'timetable_entries_teacher_fk')
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();

            $table->foreign(['room_id', 'school_id'], 'timetable_entries_room_fk')
                ->references(['id', 'school_id'])->on('rooms')
                ->restrictOnDelete();

            $table->foreign(['period_id', 'school_id'], 'timetable_entries_period_fk')
                ->references(['id', 'school_id'])->on('timetable_periods')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE timetable_entries ADD CONSTRAINT timetable_entries_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE timetable_entries ADD CONSTRAINT timetable_entries_day_of_week_check '.
            'CHECK (day_of_week BETWEEN 1 AND 7)'
        );

        // A teacher cannot be in two places during the same Period on
        // the same day, while both entries are active.
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_teacher_slot_unique ON timetable_entries '.
            '(school_id, teacher_id, day_of_week, period_id) WHERE status = \'active\''
        );

        // A Section cannot have two simultaneous classes during the
        // same Period on the same day, while both entries are active.
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_section_slot_unique ON timetable_entries '.
            '(school_id, section_id, day_of_week, period_id) WHERE status = \'active\''
        );

        // A Room cannot host two simultaneous classes during the same
        // Period on the same day, while both entries are active -- only
        // when a Room is actually assigned.
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_room_slot_unique ON timetable_entries '.
            '(school_id, room_id, day_of_week, period_id) WHERE status = \'active\' AND room_id IS NOT NULL'
        );

        TenantRls::enable('timetable_entries');
    }

    public function down(): void
    {
        TenantRls::disable('timetable_entries');
        Schema::dropIfExists('timetable_entries');
    }
};
