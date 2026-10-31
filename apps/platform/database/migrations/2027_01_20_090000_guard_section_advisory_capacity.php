<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.3 (OD-SIS-6): `sections.capacity` is the ONE advisory capacity of a
 * Section (Academic Structure owns it; nothing else stores one). Until now
 * only the application validated it (`min:1`); `unsignedInteger` is a plain
 * integer in PostgreSQL. Two structural rules move into the database
 * (ORG.8F: a rule that does not depend on who acted belongs there):
 *
 * - `sections_capacity_positive_check`: NULL means "not configured"; a
 *   configured capacity is at least 1 (no magic zero, never negative).
 * - `sections_capacity_closed_year_guard`: a closed year's Sections are
 *   history, so their capacity no longer changes (a draft or active year's
 *   still does: capacity is advisory and may be corrected below the current
 *   strength).
 *
 * Additive and data-preserving: no value is backfilled or invented. down()
 * drops exactly these two objects and touches no Section row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sections ADD CONSTRAINT sections_capacity_positive_check CHECK (capacity IS NULL OR capacity >= 1);

            CREATE FUNCTION public.sections_capacity_closed_year_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.academic_years y
                     WHERE y.id = NEW.academic_year_id AND y.school_id = NEW.school_id AND y.status = 'closed'
                ) THEN
                    RAISE EXCEPTION 'sections_capacity_closed_year_guard: section % belongs to a closed academic year', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER sections_capacity_closed_year_guard BEFORE UPDATE OF capacity ON sections
                FOR EACH ROW WHEN (OLD.capacity IS DISTINCT FROM NEW.capacity)
                EXECUTE FUNCTION public.sections_capacity_closed_year_guard();

            REVOKE ALL ON FUNCTION public.sections_capacity_closed_year_guard() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sections_capacity_closed_year_guard ON sections;
            DROP FUNCTION IF EXISTS public.sections_capacity_closed_year_guard();
            ALTER TABLE sections DROP CONSTRAINT IF EXISTS sections_capacity_positive_check;
            SQL);
    }
};
