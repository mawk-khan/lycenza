<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C9 (HRC-12, owner decision 2026-11-07): an archived Employee is restored
 * first; a new employment is never recorded for an archived identity.
 *
 * The check reads the Employee FOR SHARE, which conflicts with the FOR NO KEY
 * UPDATE that archive and restore take. A raw insert therefore cannot slip past
 * an archive still in flight; the foreign key's FOR KEY SHARE alone would not
 * have stopped it. It does not look at any earlier state: an Employee restored
 * after being archived is simply active.
 *
 * INSERT is enough: `retention_guard_end_record` already refuses moving an
 * employment to another Employee, so nothing reaches this state by UPDATE.
 *
 * Owner-role writes stay exempt, the convention of `hr_employment_records_after_death`.
 * Reversible: down() drops the trigger and function. Nothing is backfilled, and an
 * employment already held by an archived identity stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_employment_records_archived_identity() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                SELECT e.record_status INTO v_status FROM public.employees e
                 WHERE e.id = NEW.employee_id AND e.school_id = NEW.school_id FOR SHARE;
                IF v_status = 'archived' THEN
                    RAISE EXCEPTION 'employment_records: an archived Employee is restored before a new employment (hr_employment_records_archived_identity)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employment_records_archived_identity BEFORE INSERT ON employment_records
                FOR EACH ROW EXECUTE FUNCTION public.hr_employment_records_archived_identity();

            REVOKE ALL ON FUNCTION public.hr_employment_records_archived_identity() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employment_records_archived_identity ON employment_records;
            DROP FUNCTION public.hr_employment_records_archived_identity();
            SQL);
    }
};
