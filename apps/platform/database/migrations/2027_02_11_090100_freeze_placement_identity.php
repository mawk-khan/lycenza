<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C9 (HR.R1-C2 "Placement guard scope"): what a placement is, the database
 * now keeps for the runtime role.
 *
 * - `employment_record_id` never changes. A placement is history of ONE
 *   employment (HR.1A); moving someone is a new placement, never a new owner.
 * - A placement that never took effect (`ends_on < starts_on`) is final, so its
 *   `is_primary` never changes in either direction.
 * - A closed primary period stays primary history: `is_primary` is never cleared
 *   once `ends_on` is set. `EmployeeAssignmentService::setPrimaryRecord()` demotes
 *   open rows only.
 *
 * `is_primary` on an OPEN placement stays writable, a deliberate narrowing of the
 * register's acceptance wording: setPrimary() and PlacementChangeService write it
 * legitimately, and `trg_employee_assignments_primary_no_overlap` keeps one
 * primary a day. Whoever writes it there is an application boundary (rule 102).
 *
 * A separate trigger, so `trg_employee_assignments_never_effective` and its
 * function stay as published. Fired alphabetically before the overlap trigger, so
 * a reparented or never-effective row is refused for what it is.
 *
 * Owner-role writes stay exempt, the convention of the sibling guards. Reversible:
 * down() drops the trigger and function; no row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_employee_assignments_placement_identity() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF NEW.employment_record_id IS DISTINCT FROM OLD.employment_record_id THEN
                    RAISE EXCEPTION 'employee_assignments: a placement belongs to its employment for life (hr_employee_assignments_placement_identity)'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.is_primary IS DISTINCT FROM OLD.is_primary THEN
                    IF OLD.ends_on IS NOT NULL AND OLD.ends_on < OLD.starts_on THEN
                        RAISE EXCEPTION 'employee_assignments: a placement that never took effect is final (hr_employee_assignments_placement_identity)'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF OLD.is_primary AND OLD.ends_on IS NOT NULL THEN
                        RAISE EXCEPTION 'employee_assignments: a closed primary period stays primary history (hr_employee_assignments_placement_identity)'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_assignments_placement_identity
                BEFORE UPDATE OF employment_record_id, is_primary ON employee_assignments
                FOR EACH ROW EXECUTE FUNCTION public.hr_employee_assignments_placement_identity();

            REVOKE ALL ON FUNCTION public.hr_employee_assignments_placement_identity() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employee_assignments_placement_identity ON employee_assignments;
            DROP FUNCTION public.hr_employee_assignments_placement_identity();
            SQL);
    }
};
