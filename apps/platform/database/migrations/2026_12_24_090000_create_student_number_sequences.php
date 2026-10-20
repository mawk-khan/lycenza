<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7F-2 (ADR 0076 §46, owner decisions F2-D5 to F2-D7): one server-side
 * Student-number counter per School.
 *
 * - `student_number_sequences` holds the School's NEXT generated number,
 *   advanced only under a row lock (StudentNumberAllocator). Generated
 *   numbers are the plain decimal representation of the sequence value:
 *   School-wide, stable, never Campus- or year-coded.
 * - Never reused. The counter only moves forward, and the
 *   `students_advance_number_sequence` trigger moves it past ANY numeric
 *   Student number written by any path (manual entry, Admissions
 *   conversion, import) -- so a number once held by a Student, even one
 *   later purged, is never generated again. Values that are digits only
 *   count numerically (leading zeroes ignored: `0009` is 9); other values
 *   (`A-15`) never influence it. Digit strings longer than 18 characters
 *   are outside the counter's range and ignored by it (the unique index
 *   remains the final backstop).
 * - Seeded for every existing School above its greatest numeric Student
 *   number (or 1). No existing Student number is rewritten.
 * - Tenant-owned: FORCE RLS; the runtime role may read, insert and update
 *   (allocation) but never delete a counter.
 *
 * The counter only moves forward and its seed never changes (a database
 * trigger, whoever writes). Rollback refuses once the counter generated a
 * number or moved past a number written since it was seeded: dropping and
 * re-seeding could then re-issue a number held by a Student since purged.
 * An unmoved counter only mirrors existing Student numbers and is safe to
 * drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE student_number_sequences (
                school_id uuid PRIMARY KEY REFERENCES schools(id) ON DELETE CASCADE,
                next_value bigint NOT NULL,
                last_allocated_value bigint NULL,
                seeded_next_value bigint NOT NULL DEFAULT 1,
                created_at timestamp(0) without time zone NULL,
                updated_at timestamp(0) without time zone NULL,
                CONSTRAINT student_number_sequences_next_value_check CHECK (next_value >= 1),
                CONSTRAINT student_number_sequences_last_allocated_check CHECK (last_allocated_value IS NULL OR (last_allocated_value >= 1 AND last_allocated_value < next_value))
            )
            SQL);

        TenantRls::enable('student_number_sequences');
        TenantRls::revokeDelete('student_number_sequences');

        // Seed: above the greatest digits-only number of each School, else 1 --
        // one School context at a time (forced RLS applies to a non-superuser
        // migration role too).
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
            DB::insert(<<<'SQL'
                INSERT INTO student_number_sequences (school_id, next_value, seeded_next_value, created_at, updated_at)
                SELECT ?, COALESCE(max(student_number::bigint), 0) + 1, COALESCE(max(student_number::bigint), 0) + 1, now(), now()
                  FROM students
                 WHERE school_id = ? AND student_number ~ '^[0-9]{1,18}$'
                SQL, [$schoolId, $schoolId]);
        }
        DB::select("SELECT set_config('app.current_school_id', '', true)");

        // Any numeric Student number written by any path moves the counter past
        // it -- BEFORE the row is written, so a manual writer queues on the
        // counter row (behind an allocation in flight) before it claims its
        // unique index entry: no lock cycle, and a clash surfaces as the
        // ordinary duplicate-number refusal.
        DB::statement(<<<'SQL'
            CREATE FUNCTION students_advance_number_sequence() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.student_number ~ '^[0-9]{1,18}$' THEN
                    INSERT INTO public.student_number_sequences AS seq (school_id, next_value, created_at, updated_at)
                    VALUES (NEW.school_id, NEW.student_number::bigint + 1, now(), now())
                    ON CONFLICT (school_id) DO UPDATE
                        SET next_value = GREATEST(seq.next_value, EXCLUDED.next_value),
                            updated_at = CASE WHEN EXCLUDED.next_value > seq.next_value THEN now() ELSE seq.updated_at END;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION students_advance_number_sequence() FROM PUBLIC');
        DB::statement(<<<'SQL'
            CREATE TRIGGER students_advance_number_sequence
            BEFORE INSERT OR UPDATE OF student_number ON students
            FOR EACH ROW EXECUTE FUNCTION students_advance_number_sequence()
            SQL);

        // The counter never goes back and its seed never changes, whoever writes.
        DB::statement(<<<'SQL'
            CREATE FUNCTION student_number_sequences_forward_only() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.next_value < OLD.next_value OR NEW.seeded_next_value <> OLD.seeded_next_value OR NEW.school_id <> OLD.school_id THEN
                    RAISE EXCEPTION 'student_number_sequences only moves forward' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION student_number_sequences_forward_only() FROM PUBLIC');
        DB::statement(<<<'SQL'
            CREATE TRIGGER student_number_sequences_forward_only
            BEFORE UPDATE ON student_number_sequences
            FOR EACH ROW EXECUTE FUNCTION student_number_sequences_forward_only()
            SQL);
    }

    public function down(): void
    {
        // A CHECK is validated against EVERY row regardless of RLS (a plain
        // SELECT here would see nothing under FORCE RLS): any generated
        // number aborts the rollback instead of risking its reuse.
        try {
            DB::statement('ALTER TABLE student_number_sequences ADD CONSTRAINT student_number_sequences_rollback_unmoved CHECK (last_allocated_value IS NULL AND next_value = seeded_next_value)');
        } catch (Throwable $e) {
            throw new RuntimeException('student_number_sequences cannot be rolled back: it generated numbers or moved past a number written since it was seeded, and re-seeding could re-issue one (ADR 0076 §46). Keep the table.', previous: $e);
        }

        DB::statement('DROP TRIGGER IF EXISTS students_advance_number_sequence ON students');
        DB::statement('DROP FUNCTION IF EXISTS students_advance_number_sequence()');
        DB::statement('DROP TRIGGER IF EXISTS student_number_sequences_forward_only ON student_number_sequences');
        DB::statement('DROP FUNCTION IF EXISTS student_number_sequences_forward_only()');
        TenantRls::disable('student_number_sequences');
        DB::statement('DROP TABLE IF EXISTS student_number_sequences');
    }
};
