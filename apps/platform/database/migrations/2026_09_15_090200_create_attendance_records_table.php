<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.2 (Student Attendance foundation): one StudentEnrollment's
     * attendance status inside one submitted register. Written only by
     * `App\Domain\Attendance\Application\AttendanceSubmissionService`
     * (creation, as a complete set) and mutated only by
     * `App\Domain\Attendance\Application\AttendanceCorrectionService`
     * (expected-status compare-and-swap on `status`/`corrected_at`).
     * No hard delete, ever.
     *
     * `student_enrollment_id` is PROVENANCE: the placement that
     * qualified this Student for this register AT SUBMISSION TIME. A
     * later transfer/withdrawal/completion/cancellation/rollover --
     * including a BACKDATED one -- never rewrites Attendance, so this
     * column must NOT be read as a claim that the referenced
     * Enrollment's CURRENT `[starts_on, ends_on]` interval still
     * contains `attendance_date`. Attendance is authoritative once
     * submitted (docs/modules/ATTENDANCE.md).
     *
     * There is deliberately no `student_id` column: Student identity is
     * derived THROUGH the Enrollment. There is deliberately no note/
     * reason/remark/minutes-late/evidence column of any kind --
     * `excused` records the generic status and never why (Sensitive
     * classification, docs/security/DATA-CLASSIFICATION.md); a medical
     * or safeguarding explanation would pull Health-tier data into this
     * table and is explicitly out of Phase 0H.2 scope.
     *
     * STRUCTURAL MEMBERSHIP BINDING -- the reason `academic_year_id`/
     * `campus_id`/`grade_level_id`/`section_id` exist on this row.
     * Same-School FKs alone would prove only "this Session exists" and
     * "this Enrollment exists", never "this Enrollment belongs to this
     * Session's Section". A raw INSERT could then mark a Section B
     * student absent on a Section A register. The two composite FKs
     * below reference the SAME five physical columns of this row, so a
     * row exists only if the Session's context and the Enrollment's
     * context are byte-identical. Divergence between the two is not
     * representable -- there is only one physical copy of each value --
     * which is why these columns can never drift (proven in
     * `Tests\Feature\Postgres\AttendanceRecordsContextIntegrityTest`,
     * from BOTH directions).
     *
     * These four columns are structural ONLY: server-derived from the
     * just-inserted Session, never client input, never updated, and
     * never serialized in any API response. They are not reporting
     * convenience copies -- a report joins the Session.
     *
     * Every column in both composite FKs is NOT NULL, so PostgreSQL's
     * MATCH SIMPLE "skip the check if any referencing column is NULL"
     * bypass cannot apply here and no trigger is needed.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('attendance_session_id');
            $table->uuid('student_enrollment_id');

            // Structural context columns -- see docblock. Shared by BOTH
            // composite FKs below.
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('section_id');

            $table->string('status'); // present|absent|late|excused -- see CHECK below
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);

            // One record per Enrollment per submitted register.
            $table->unique(
                ['attendance_session_id', 'student_enrollment_id'],
                'attendance_records_session_enrollment_unique',
            );

            $table->index(['attendance_session_id']);
            $table->index(['student_enrollment_id']);
            $table->index(['school_id', 'status']);

            $table->foreign(
                ['attendance_session_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
                'attendance_records_session_context_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'])
                ->on('attendance_sessions')
                ->restrictOnDelete();

            $table->foreign(
                ['student_enrollment_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
                'attendance_records_enrollment_context_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'])
                ->on('student_enrollments')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check '.
            "CHECK (status IN ('present', 'absent', 'late', 'excused'))"
        );

        TenantRls::enable('attendance_records');
    }

    public function down(): void
    {
        TenantRls::disable('attendance_records');
        Schema::dropIfExists('attendance_records');
    }
};
