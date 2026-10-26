<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SIS 5.1 (MC-3, ADR 0077): a DRAFT Academic Year's dates may be corrected
 * while it stays draft; an active or closed year's dates stay immutable.
 *
 * 1. `academic_years_freeze_dates` (E21.3D) refused every date change, for
 *    every status and role. It now lets a date change through only when the
 *    row is draft both before AND after the UPDATE: a draft year activated
 *    and re-dated in one statement, or an active/closed year marked draft
 *    and re-dated in one statement, is still refused. The trigger itself
 *    (BEFORE UPDATE OF starts_on, ends_on, only when a date really changes)
 *    is unchanged.
 *
 * 2. `academic_years_no_return_to_draft` (new): no UPDATE may set `status`
 *    back to `draft` once a year has left it -- otherwise "mark it draft,
 *    then re-date it" in two statements would bypass (1). Draft is an
 *    initial state only; activation and closure are untouched, and nothing
 *    in the application ever returns a year to draft.
 *
 * Why the retention clock (E21.3D's reason for the freeze) still holds: a
 * draft year has never been operational, and since E21-RH.7 every retention
 * delete also requires each row's database-recorded anchor
 * (`retention_recorded_at`) to predate the cutoff, so re-dating a draft year
 * can never make a row eligible sooner than its full period from recording.
 * The dependent-data rules (Terms, placements, teaching assignments,
 * examinations, LMS due dates, fee instalments and concessions inside the
 * year) are checked by AcademicYearService::correctDraftDates() under the
 * year's FOR UPDATE lock; every dependent writer share-locks the year.
 *
 * Rollback restores the absolute freeze and drops the new guard. Dates
 * corrected meanwhile stay as corrected (no data is rewritten either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION academic_years_freeze_dates() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                -- SIS 5.1: a draft year's dates may be corrected while it stays draft.
                IF OLD.status = 'draft' AND NEW.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'academic_years: the date range is fixed once the year leaves draft (the retention clock of everything in the year depends on it)'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE FUNCTION academic_years_no_return_to_draft() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'academic_years: a year never returns to draft (academic_years_no_return_to_draft)'
                    USING ERRCODE = 'check_violation';
            END;
            $$;
            REVOKE ALL ON FUNCTION academic_years_no_return_to_draft() FROM PUBLIC;

            CREATE TRIGGER academic_years_no_return_to_draft
                BEFORE UPDATE OF status ON academic_years
                FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM 'draft' AND NEW.status = 'draft')
                EXECUTE FUNCTION academic_years_no_return_to_draft();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS academic_years_no_return_to_draft ON academic_years;
            DROP FUNCTION IF EXISTS academic_years_no_return_to_draft();

            CREATE OR REPLACE FUNCTION academic_years_freeze_dates() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'academic_years: the date range is fixed at creation (the retention clock of everything in the year depends on it)'
                    USING ERRCODE = 'check_violation';
            END;
            $$;
            SQL);
    }
};
