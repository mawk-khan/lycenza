<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.8E (ADR 0076 §63, owner decision 8E-D2): every correction of an
 * already-recorded Student Attendance fact carries a closed, code-owned
 * reason and its own immutable evidence row -- the Student Attendance
 * counterpart of `staff_attendance_corrections` (HRX.3). First-time entry
 * (a submitted register) is not a correction and is untouched.
 *
 * `attendance_records_correction_evidence` (BEFORE UPDATE OF status): a
 * record's status changes only with its correction row (same record, from
 * the old status to the new one), written in the same transaction
 * (`corrected_at` = the transaction's timestamp). Append-only, FORCE RLS;
 * School and User RESTRICT; the record key cascades (the evidence leaves
 * with its record under D7 retention). No approval, no time window (8E-D2), no
 * free text. The codes say why the RECORD was corrected, never why a Student
 * was absent (AttendanceArchitectureGuardTest: no reason note, medical or
 * evidence field) -- the Staff Attendance correction set (ADR 0065 §24.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_record_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('attendance_record_id');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('reason_code', 32);
            $table->foreignUuid('corrected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('corrected_at', 6)->useCurrent(); // microseconds: it must equal now() exactly

            $table->index(['school_id', 'attendance_record_id', 'corrected_at'], 'attendance_record_corrections_record_idx');
            // Evidence of one record: it leaves with that record under D7 retention (declared handled there).
            $table->foreign(['attendance_record_id', 'school_id'], 'attendance_record_corrections_record_fk')
                ->references(['id', 'school_id'])->on('attendance_records')->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_record_corrections ADD CONSTRAINT attendance_record_corrections_shape_check CHECK (
                from_status IN ('present', 'absent', 'late', 'excused')
                AND to_status IN ('present', 'absent', 'late', 'excused')
                AND from_status <> to_status
                AND reason_code IN ('entered_in_error', 'late_information', 'administrative_review')
            );

            CREATE FUNCTION public.attendance_records_require_correction_evidence() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.attendance_record_corrections c
                     WHERE c.school_id = NEW.school_id AND c.attendance_record_id = NEW.id
                       AND c.from_status = OLD.status AND c.to_status = NEW.status AND c.corrected_at = now()
                ) THEN
                    RAISE EXCEPTION 'attendance_records_correction_evidence: attendance record % changed without its correction', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER attendance_records_correction_evidence BEFORE UPDATE OF status ON attendance_records
                FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status)
                EXECUTE FUNCTION public.attendance_records_require_correction_evidence();

            REVOKE ALL ON FUNCTION public.attendance_records_require_correction_evidence() FROM PUBLIC;
            SQL);

        TenantRls::enable('attendance_record_corrections');
        TenantRls::makeAppendOnly('attendance_record_corrections');
    }

    public function down(): void
    {
        if (DB::table('attendance_record_corrections')->exists()) {
            throw new RuntimeException('Refusing to roll back: attendance_record_corrections holds correction history (ORG.8E).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS attendance_records_correction_evidence ON attendance_records;
            DROP FUNCTION IF EXISTS public.attendance_records_require_correction_evidence();
            SQL);
        TenantRls::disable('attendance_record_corrections');
        Schema::dropIfExists('attendance_record_corrections');
    }
};
