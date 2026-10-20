<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decisions F1-D6, F3-D1): a Student
 * placement's CONTEXT is fixed once the row exists.
 *
 * `student_enrollments_freeze_placement` refuses, for every role but the
 * table owner (migrations, operator repair -- the same exemption
 * `retention_guard_end_record` uses), any UPDATE that changes
 * `academic_year_id`, `campus_id`, `grade_level_id` or `section_id`.
 * `retention_eligibility_guard` already fixes `id`, `school_id`,
 * `student_id` and `starts_on`, and an ended record's end and status.
 *
 * Placement changes happen only through the named lifecycle operations:
 * a transfer ENDS the source row (status/`ends_on`) and INSERTS the
 * destination row -- it never rewrites a placement. The lifecycle columns
 * (`status`, `ends_on`) and `roll_number` (no writer today; not part of the
 * Campus/Section identity) stay mutable under their existing rules.
 *
 * Inventory before this freeze (ORG.7F-2 §46.8, re-verified): the only
 * runtime INSERT is StudentEnrollmentService::createEnrollmentRow; the only
 * UPDATEs set `status`/`ends_on` (transfer, complete, withdraw, cancel). No
 * production writer updates a placement column, and no data is rewritten.
 *
 * Rollback drops the trigger and function (direct placement updates by the
 * runtime role become possible again; no data changes either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE FUNCTION student_enrollments_freeze_placement() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                -- The schema owner (migrations, operator repair) is exempt: it owns the table.
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                   OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                   OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                   OR NEW.section_id IS DISTINCT FROM OLD.section_id THEN
                    RAISE EXCEPTION 'student_enrollments: a placement''s year, Campus, Grade and Section are fixed; transfer ends it and creates a new one (student_enrollments_freeze_placement)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION student_enrollments_freeze_placement() FROM PUBLIC');
        DB::statement(<<<'SQL'
            CREATE TRIGGER student_enrollments_freeze_placement
            BEFORE UPDATE OF academic_year_id, campus_id, grade_level_id, section_id ON student_enrollments
            FOR EACH ROW EXECUTE FUNCTION student_enrollments_freeze_placement()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS student_enrollments_freeze_placement ON student_enrollments');
        DB::statement('DROP FUNCTION IF EXISTS student_enrollments_freeze_placement()');
    }
};
