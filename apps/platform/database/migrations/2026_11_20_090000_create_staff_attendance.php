<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRX.3 (ADR 0065 §24): daily, administrative Staff Attendance with exact
 * half-day evidence and append-only corrections.
 *
 * Employee evidence (E21-D9, kept with the Employee, RESTRICT):
 * - `staff_attendance_records`:
 *   - one row per School x EmploymentRecord x date (unique key);
 *   - `first_half_status` / `second_half_status`: `present`, `absent` or
 *     NULL (no evidence for that half). Leave, holidays and weekly offs are
 *     never stored here; the read model derives them (§24.3);
 *   - `employee_id` is derived from the EmploymentRecord by trigger;
 *   - a new row has evidence for at least one half and starts at version 1;
 *   - the row changes ONLY with its correction evidence: an UPDATE raises the
 *     version by exactly one, touches only the halves, and must match a
 *     correction row (same versions, before/after values). No DELETE.
 * - `staff_attendance_corrections`: append-only. A correction's from-version
 *   and before-values must equal the record's current ones; the chain is
 *   unique per (record, from-version) with to = from + 1; a deferred
 *   constraint trigger refuses, at commit, a correction never applied.
 *
 * No medical, free-text, note, attachment, device, location or clock column
 * (ADR 0065 §10, §24.14).
 */
return new class extends Migration
{
    private const TABLES = ['staff_attendance_corrections', 'staff_attendance_records'];

    public function up(): void
    {
        Schema::create('staff_attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('employee_id');
            $table->date('attendance_date');
            $table->string('first_half_status', 8)->nullable();
            $table->string('second_half_status', 8)->nullable();
            $table->integer('version')->default(1);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'employment_record_id']);
            $table->unique(['school_id', 'employment_record_id', 'attendance_date'], 'staff_attendance_records_one_per_day');
            $table->index(['school_id', 'attendance_date'], 'staff_attendance_records_date_idx');
            $table->foreign(['employment_record_id', 'school_id'], 'staff_attendance_records_employment_fk')
                ->references(['id', 'school_id'])->on('employment_records')->restrictOnDelete();
            $table->foreign(['employee_id', 'school_id'], 'staff_attendance_records_employee_fk')
                ->references(['id', 'school_id'])->on('employees')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE staff_attendance_records ADD CONSTRAINT staff_attendance_records_shape_check CHECK ('
            ."(first_half_status IS NULL OR first_half_status IN ('present', 'absent')) "
            ."AND (second_half_status IS NULL OR second_half_status IN ('present', 'absent')) "
            .'AND version >= 1 '
            // A new record carries evidence; only a correction can clear both halves (§24.8).
            .'AND (first_half_status IS NOT NULL OR second_half_status IS NOT NULL OR version > 1))');

        Schema::create('staff_attendance_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('staff_attendance_record_id');
            $table->uuid('employment_record_id');
            $table->integer('from_version');
            $table->integer('to_version');
            $table->string('before_first_half_status', 8)->nullable();
            $table->string('before_second_half_status', 8)->nullable();
            $table->string('after_first_half_status', 8)->nullable();
            $table->string('after_second_half_status', 8)->nullable();
            $table->string('reason_code', 32);
            $table->foreignUuid('corrected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'staff_attendance_record_id', 'from_version'], 'staff_attendance_corrections_chain');
            $table->foreign(['staff_attendance_record_id', 'school_id', 'employment_record_id'], 'staff_attendance_corrections_record_fk')
                ->references(['id', 'school_id', 'employment_record_id'])->on('staff_attendance_records')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE staff_attendance_corrections ADD CONSTRAINT staff_attendance_corrections_shape_check CHECK ('
            .'from_version >= 1 AND to_version = from_version + 1 '
            ."AND (before_first_half_status IS NULL OR before_first_half_status IN ('present', 'absent')) "
            ."AND (before_second_half_status IS NULL OR before_second_half_status IN ('present', 'absent')) "
            ."AND (after_first_half_status IS NULL OR after_first_half_status IN ('present', 'absent')) "
            ."AND (after_second_half_status IS NULL OR after_second_half_status IN ('present', 'absent')) "
            .'AND (before_first_half_status IS DISTINCT FROM after_first_half_status OR before_second_half_status IS DISTINCT FROM after_second_half_status) '
            ."AND reason_code IN ('entered_in_error', 'late_information', 'administrative_review', 'other') "
            ."AND (after_first_half_status IS NOT NULL OR after_second_half_status IS NOT NULL OR reason_code = 'entered_in_error'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION staff_attendance_records_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.version <> 1 THEN
                        RAISE EXCEPTION 'staff_attendance_records: a record starts at version 1 (staff_attendance_version_invalid)';
                    END IF;
                    SELECT employee_id INTO NEW.employee_id FROM employment_records WHERE id = NEW.employment_record_id AND school_id = NEW.school_id;
                    IF NEW.employee_id IS NULL THEN
                        RAISE EXCEPTION 'staff_attendance_records: unknown employment record';
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - 'first_half_status' - 'second_half_status' - 'version' - 'updated_at')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'first_half_status' - 'second_half_status' - 'version' - 'updated_at') THEN
                    RAISE EXCEPTION 'staff_attendance_records: only the halves change, by correction (staff_attendance_immutable)';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'staff_attendance_records: a correction raises the version by exactly one (staff_attendance_version_invalid)';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM staff_attendance_corrections c
                                WHERE c.school_id = NEW.school_id AND c.staff_attendance_record_id = NEW.id
                                  AND c.from_version = OLD.version AND c.to_version = NEW.version
                                  AND c.before_first_half_status IS NOT DISTINCT FROM OLD.first_half_status
                                  AND c.before_second_half_status IS NOT DISTINCT FROM OLD.second_half_status
                                  AND c.after_first_half_status IS NOT DISTINCT FROM NEW.first_half_status
                                  AND c.after_second_half_status IS NOT DISTINCT FROM NEW.second_half_status) THEN
                    RAISE EXCEPTION 'staff_attendance_records: a change needs its correction evidence (staff_attendance_correction_missing)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_records_guard BEFORE INSERT OR UPDATE ON staff_attendance_records FOR EACH ROW EXECUTE FUNCTION staff_attendance_records_guard();

            CREATE FUNCTION staff_attendance_corrections_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_record staff_attendance_records%ROWTYPE;
            BEGIN
                SELECT * INTO v_record FROM staff_attendance_records
                 WHERE id = NEW.staff_attendance_record_id AND school_id = NEW.school_id FOR UPDATE;
                IF v_record.id IS NULL
                   OR v_record.employment_record_id <> NEW.employment_record_id
                   OR v_record.version <> NEW.from_version
                   OR v_record.first_half_status IS DISTINCT FROM NEW.before_first_half_status
                   OR v_record.second_half_status IS DISTINCT FROM NEW.before_second_half_status THEN
                    RAISE EXCEPTION 'staff_attendance_corrections: a correction starts from the record''s current version and values (staff_attendance_correction_stale)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_corrections_guard BEFORE INSERT ON staff_attendance_corrections FOR EACH ROW EXECUTE FUNCTION staff_attendance_corrections_guard();

            CREATE FUNCTION staff_attendance_corrections_applied() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM staff_attendance_records
                                WHERE id = NEW.staff_attendance_record_id AND school_id = NEW.school_id AND version >= NEW.to_version) THEN
                    RAISE EXCEPTION 'staff_attendance_corrections: a correction is applied to its record in the same transaction (staff_attendance_correction_unapplied)';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER trg_staff_attendance_corrections_applied AFTER INSERT ON staff_attendance_corrections
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION staff_attendance_corrections_applied();
            SQL);

        foreach (self::TABLES as $table) {
            TenantRls::enable($table);
        }
        TenantRls::makeAppendOnly('staff_attendance_corrections');
        TenantRls::revokeDelete('staff_attendance_records');
    }

    public function down(): void
    {
        if (DB::table('staff_attendance_records')->exists()) {
            throw new RuntimeException('Refusing to roll back: Staff Attendance holds Employee evidence (HRX.3). Remove it through its retention path first.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_staff_attendance_corrections_applied ON staff_attendance_corrections;
            DROP TRIGGER IF EXISTS trg_staff_attendance_corrections_guard ON staff_attendance_corrections;
            DROP TRIGGER IF EXISTS trg_staff_attendance_records_guard ON staff_attendance_records;
            DROP FUNCTION IF EXISTS staff_attendance_corrections_applied();
            DROP FUNCTION IF EXISTS staff_attendance_corrections_guard();
            DROP FUNCTION IF EXISTS staff_attendance_records_guard();
            SQL);

        foreach (self::TABLES as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
