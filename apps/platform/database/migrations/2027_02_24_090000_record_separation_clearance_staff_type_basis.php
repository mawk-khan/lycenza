<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-21 (HRC-8; owner decisions D-C35-4, D-WO21-1).
 *
 * A separation's clearance checklist is taken at approval from the School's active requirements: those for
 * every staff type, plus those scoped to the employment's staff type. An UNCLASSIFIED employment (staff type
 * NULL) silently got none of the staff-type requirements, and the case never said so. The approval now
 * records the basis of the checklist, frozen:
 *
 * - `clearance_staff_type`: the employment's staff type the checklist used (NULL = unclassified);
 * - `clearance_skipped_requirement_ids`: for an unclassified employment, the ids (sorted) of every active
 *   staff-type-scoped requirement that was therefore NOT applied; empty for a classified one (other types'
 *   requirements do not apply to it, they are not skipped). NULL = recorded before WO-21 ("not recorded").
 *
 * The guard re-derives both from the locked employment and the School's active requirements at the approval,
 * refuses any other value, refuses them outside the approval and refuses any later change. A case approved
 * before this migration is never back-filled: today's classification is not its history. Nothing new blocks
 * completion (D-C35-4: show, do not require). SECURITY INVOKER, path pinned, schema-qualified.
 *
 * The items follow the basis too (review LOW 3, rule 102 ORG.8F): once a case records its basis, a clearance
 * item is inserted only for a universal requirement or one of the recorded staff type, so a requirement named
 * "not applied" can never also be an item (trg_separation_clearance_items_staff_type_basis).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employment_separation_cases ADD COLUMN clearance_staff_type varchar(20)');
        DB::statement('ALTER TABLE employment_separation_cases ADD COLUMN clearance_skipped_requirement_ids uuid[]');
        DB::statement("ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_clearance_basis_shape
            CHECK ((clearance_staff_type IS NULL OR clearance_staff_type IN ('teaching', 'non_teaching', 'outsourced'))
                AND (clearance_staff_type IS NULL OR clearance_skipped_requirement_ids IS NOT NULL)
                AND (clearance_staff_type IS NULL OR cardinality(clearance_skipped_requirement_ids) = 0))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_separation_clearance_basis_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_staff_type text;
                v_skipped uuid[];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.clearance_staff_type IS NOT NULL OR NEW.clearance_skipped_requirement_ids IS NOT NULL THEN
                        RAISE EXCEPTION 'a separation case is born without a clearance basis (hr_separation_clearance_basis_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'requested' AND NEW.status = 'approved' THEN
                    SELECT er.staff_type INTO v_staff_type FROM public.employment_records er WHERE er.id = NEW.employment_record_id;
                    IF v_staff_type IS NULL THEN
                        SELECT COALESCE(array_agg(r.id ORDER BY r.id), '{}'::uuid[]) INTO v_skipped
                          FROM public.separation_clearance_requirements r
                         WHERE r.school_id = NEW.school_id AND r.status = 'active' AND r.staff_type IS NOT NULL;
                    ELSE
                        v_skipped := '{}'::uuid[];
                    END IF;
                    IF NEW.clearance_skipped_requirement_ids IS NULL
                        OR NEW.clearance_staff_type IS DISTINCT FROM v_staff_type
                        OR NEW.clearance_skipped_requirement_ids IS DISTINCT FROM v_skipped THEN
                        RAISE EXCEPTION 'the approval records the staff type its checklist used and the staff-type requirements it did not apply (hr_separation_clearance_basis_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.clearance_staff_type IS DISTINCT FROM OLD.clearance_staff_type
                    OR NEW.clearance_skipped_requirement_ids IS DISTINCT FROM OLD.clearance_skipped_requirement_ids THEN
                    RAISE EXCEPTION 'the clearance basis is recorded once, at approval (hr_separation_clearance_basis_guard)' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employment_separation_cases_staff_type_basis BEFORE INSERT OR UPDATE ON employment_separation_cases
                FOR EACH ROW EXECUTE FUNCTION public.hr_separation_clearance_basis_guard();

            CREATE FUNCTION public.hr_separation_clearance_item_basis_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_case_type text;
                v_recorded uuid[];
                v_req_type text;
            BEGIN
                SELECT c.clearance_staff_type, c.clearance_skipped_requirement_ids INTO v_case_type, v_recorded
                  FROM public.employment_separation_cases c WHERE c.id = NEW.case_id;
                SELECT r.staff_type INTO v_req_type FROM public.separation_clearance_requirements r WHERE r.id = NEW.requirement_id;
                IF v_recorded IS NOT NULL AND v_req_type IS NOT NULL AND v_req_type IS DISTINCT FROM v_case_type THEN
                    RAISE EXCEPTION 'a clearance item belongs to the staff type its checklist recorded (hr_separation_clearance_item_basis_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_separation_clearance_items_staff_type_basis BEFORE INSERT ON separation_clearance_items
                FOR EACH ROW EXECUTE FUNCTION public.hr_separation_clearance_item_basis_guard();
            SQL);
    }

    /** Drops the recorded bases with the columns; rollback runs only in local and testing (rule 143), production corrects forward. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_separation_clearance_items_staff_type_basis ON separation_clearance_items;
            DROP FUNCTION IF EXISTS public.hr_separation_clearance_item_basis_guard();
            DROP TRIGGER IF EXISTS trg_employment_separation_cases_staff_type_basis ON employment_separation_cases;
            DROP FUNCTION IF EXISTS public.hr_separation_clearance_basis_guard();
            ALTER TABLE employment_separation_cases DROP CONSTRAINT IF EXISTS employment_separation_cases_clearance_basis_shape;
            ALTER TABLE employment_separation_cases DROP COLUMN IF EXISTS clearance_skipped_requirement_ids;
            ALTER TABLE employment_separation_cases DROP COLUMN IF EXISTS clearance_staff_type;
            SQL);
    }
};
