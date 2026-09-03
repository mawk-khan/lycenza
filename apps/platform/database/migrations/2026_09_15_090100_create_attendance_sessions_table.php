<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.2 (Student Attendance foundation): the immutable header
     * of one SUBMITTED class register -- "Section A's Mathematics class
     * during Period 1 on 15 September 2026 was taken, by this user, at
     * this time". Every write goes through
     * `App\Domain\Attendance\Application\AttendanceSubmissionService`
     * -- never written directly anywhere else (see that class's
     * docblock and `Tests\Feature\Attendance\AttendanceArchitectureGuardTest`).
     *
     * NO status column and NO update lifecycle: a Session either exists
     * (the register was submitted) or it does not. There is no draft,
     * no partial save, no replace, and no delete -- every column below
     * is immutable after INSERT. Corrections happen on individual
     * `attendance_records` rows via an explicit expected-status
     * compare-and-swap command, never by rewriting this header.
     *
     * IMMUTABLE HISTORICAL SNAPSHOT (the core of this table's design).
     * `academic_year_id`/`campus_id`/`grade_level_id`/`section_id`/
     * `subject_offering_id`/`teacher_id`/`period_id`/`period_start_time`/
     * `period_end_time` are all COPIED, server-side, from the
     * TimetableEntry (and its Period) while that entry is held under
     * `SELECT ... FOR UPDATE`. They are never accepted from client
     * input. They exist because Phase 0H.1's `timetable_entries` row is
     * fully MUTABLE after this Session is written:
     * `TimetableScheduleService::update()` can change its
     * SubjectOffering, Section, teacher, Room, Period and day-of-week
     * (and, via `deriveContext()`, all three context columns) at any
     * time, including while the entry is inactive. Resolving a
     * historical register by joining through `timetable_entry_id` would
     * therefore silently re-label a Mathematics/Section A register as a
     * Science/Section B one. `timetable_entry_id` is PROVENANCE ONLY --
     * see this table's own `attendance_sessions_timetable_entry_fk`
     * and docs/modules/ATTENDANCE.md.
     *
     * `period_start_time`/`period_end_time` are snapshotted for the
     * same reason one level further up the parent chain:
     * `TimetablePeriodService::update()` rejects a start/end change
     * only while an ACTIVE TimetableEntry references the Period
     * (`assertNotReferencedByActiveEntry()`), and
     * `TimetablePeriodReferencedException`'s own docblock prescribes
     * "deactivate/reassign every referencing TimetableEntry first" as
     * the SUPPORTED way to retime a Period. Once that happens, a
     * historical register joining `period_id -> timetable_periods`
     * would report the NEW wall-clock time for a class that ran at the
     * old one. These two columns are historical values, NOT foreign-key
     * components. Period `code`/`name` are deliberately NOT snapshotted
     * -- they remain current human-facing labels resolved through
     * `period_id` (docs/modules/ATTENDANCE.md, "Period label vs
     * historical Period time").
     *
     * `room_id` and `day_of_week` are deliberately absent: Room is
     * logistical and outside Attendance's historical identity (this is
     * not a room-usage ledger), and weekday is always derivable from
     * `attendance_date`. `subject_id` is likewise absent -- no
     * supported SubjectOffering write path can mutate
     * `subject_offerings.subject_id` (proven by
     * `Tests\Feature\Attendance\SubjectOfferingIdentityGuardTest`), so
     * `subject_offering_id` is already a stable historical Subject
     * anchor and a duplicate column would be redundant.
     *
     * Excluding the nullable `room_id` also keeps EVERY column
     * participating in a composite FK below NOT NULL, which matters:
     * under PostgreSQL's default MATCH SIMPLE a foreign-key check is
     * skipped entirely whenever any referencing column is NULL -- a
     * bypass this repository has already proven empirically and had to
     * install a trigger to close (Phase 1F.1's
     * `assert_student_subject_enrollment_elective_group_snapshot()`).
     * Attendance needs no such trigger.
     */
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            // Provenance only -- NOT historical authority. See docblock.
            $table->uuid('timetable_entry_id');
            $table->date('attendance_date');

            // Immutable historical class context, all server-derived.
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('section_id');
            $table->uuid('subject_offering_id');
            $table->uuid('teacher_id');
            $table->uuid('period_id');
            $table->time('period_start_time');
            $table->time('period_end_time');

            $table->uuid('submitted_by_user_id');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['id', 'school_id']);

            // Parent key for attendance_records' structural membership
            // binding (attendance_records_session_context_fk).
            $table->unique(
                ['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
                'attendance_sessions_context_unique',
            );

            // One submitted register per source TimetableEntry per date.
            $table->unique(
                ['school_id', 'timetable_entry_id', 'attendance_date'],
                'attendance_sessions_entry_date_unique',
            );

            // One register per cohort/Period-identity/date, INDEPENDENT
            // of which TimetableEntry instantiated it: deactivating an
            // entry immediately frees its slot (every timetable_entries
            // double-booking index is scoped `WHERE status='active'`),
            // so a recreated entry could otherwise produce a second
            // register for the same Section/Period/date.
            $table->unique(
                ['school_id', 'section_id', 'period_id', 'attendance_date'],
                'attendance_sessions_section_slot_unique',
            );

            $table->index(['school_id', 'attendance_date']);
            $table->index(['section_id']);
            $table->index(['academic_year_id']);
            $table->index(['teacher_id']);
            $table->index(['timetable_entry_id']);
            $table->index(['subject_offering_id']);
            $table->index(['period_id']);

            // Both context FKs below pin their parent to the SAME
            // AcademicYear/Campus/GradeLevel carried on this row, so a
            // Session can never reference a Section and a
            // SubjectOffering from different contexts -- even via a raw
            // SQL insert (CLAUDE.md rule 70; the identical shape
            // `timetable_entries` already uses).
            $table->foreign(
                ['section_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'attendance_sessions_section_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('sections')
                ->restrictOnDelete();

            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'attendance_sessions_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();

            $table->foreign(['teacher_id', 'school_id'], 'attendance_sessions_teacher_fk')
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();

            $table->foreign(['period_id', 'school_id'], 'attendance_sessions_period_fk')
                ->references(['id', 'school_id'])->on('timetable_periods')
                ->restrictOnDelete();

            // Provenance FK. Guarantees the source entry exists in this
            // School and can never be hard-deleted out from under a
            // submitted register. It does NOT, and must not be read as
            // if it did, guarantee the entry's CURRENT field values are
            // still this register's historical truth.
            $table->foreign(['timetable_entry_id', 'school_id'], 'attendance_sessions_timetable_entry_fk')
                ->references(['id', 'school_id'])->on('timetable_entries')
                ->restrictOnDelete();

            $table->foreign('submitted_by_user_id', 'attendance_sessions_submitted_by_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        // Structural backstop mirroring `timetable_periods`' own
        // `timetable_periods_start_before_end_check`, asserted here
        // because this is where the historical value now lives.
        DB::statement(
            'ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_period_times_check '.
            'CHECK (period_start_time < period_end_time)'
        );

        TenantRls::enable('attendance_sessions');
    }

    public function down(): void
    {
        TenantRls::disable('attendance_sessions');
        Schema::dropIfExists('attendance_sessions');
    }
};
