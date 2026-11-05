<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C2 gap closure (structural rules PostgreSQL can enforce without
 * knowing who acted, ADR 0076 §64).
 *
 * `trg_employee_assignments_never_effective` watched `ends_on` only, so the
 * widened CHECK could be reached through `starts_on`, and a never-effective
 * placement could be reopened. It now:
 *  - fires on `starts_on` as well as `ends_on`;
 *  - treats a never-effective placement as final;
 *  - keeps every placement of an ENDED employment within that employment.
 *    It reads the employment FOR KEY SHARE, so it waits for an end in progress
 *    (EmploymentService::end() holds the row FOR UPDATE), and it costs a
 *    concurrent status change nothing.
 *
 * `trg_employment_records_after_death` backs the service rule that an Employee
 * recorded deceased never receives another employment. The schema owner stays
 * exempt, exactly as in `retention_guard_end_record` (governed historical
 * import).
 *
 * Rollback removes guards only and touches no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.hr_employee_assignments_never_effective() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_employment_end date;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.starts_on IS NOT DISTINCT FROM OLD.starts_on AND NEW.ends_on IS NOT DISTINCT FROM OLD.ends_on THEN
                        RETURN NEW;
                    END IF;
                    IF OLD.ends_on IS NOT NULL AND OLD.ends_on = OLD.starts_on - 1 THEN
                        RAISE EXCEPTION 'employee_assignments: a placement that never took effect is final (hr_employee_assignments_never_effective)'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                SELECT er.ends_on INTO v_employment_end FROM public.employment_records er
                 WHERE er.id = NEW.employment_record_id AND er.school_id = NEW.school_id FOR KEY SHARE;

                IF NEW.ends_on IS NOT NULL AND NEW.ends_on = NEW.starts_on - 1 THEN
                    IF TG_OP = 'UPDATE' AND NEW.starts_on = OLD.starts_on AND v_employment_end IS NOT NULL AND v_employment_end < NEW.starts_on THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'employee_assignments: a placement is recorded as never effective only when its employment ended before it starts (hr_employee_assignments_never_effective)'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_employment_end IS NOT NULL AND (NEW.starts_on > v_employment_end OR NEW.ends_on IS NULL OR NEW.ends_on > v_employment_end) THEN
                    RAISE EXCEPTION 'employee_assignments: a placement of an ended employment never runs past its end (hr_employee_assignments_never_effective)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER trg_employee_assignments_never_effective ON employee_assignments;
            CREATE TRIGGER trg_employee_assignments_never_effective
                BEFORE INSERT OR UPDATE OF starts_on, ends_on ON employee_assignments
                FOR EACH ROW EXECUTE FUNCTION public.hr_employee_assignments_never_effective();

            CREATE FUNCTION public.hr_employment_records_after_death() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF EXISTS (SELECT 1 FROM public.employment_records r
                            WHERE r.school_id = NEW.school_id AND r.employee_id = NEW.employee_id AND r.status = 'deceased') THEN
                    RAISE EXCEPTION 'employment_records: an Employee recorded deceased never receives another employment (hr_employment_records_after_death)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employment_records_after_death BEFORE INSERT ON employment_records
                FOR EACH ROW EXECUTE FUNCTION public.hr_employment_records_after_death();

            REVOKE ALL ON FUNCTION public.hr_employment_records_after_death() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employment_records_after_death ON employment_records;
            DROP FUNCTION public.hr_employment_records_after_death();

            DROP TRIGGER trg_employee_assignments_never_effective ON employee_assignments;
            CREATE OR REPLACE FUNCTION public.hr_employee_assignments_never_effective() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_employment_end date;
            BEGIN
                -- Only the empty interval is this trigger's question; any other impossible range is the CHECK's.
                IF NEW.ends_on IS NULL OR NEW.ends_on <> NEW.starts_on - 1 THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    SELECT er.ends_on INTO v_employment_end FROM public.employment_records er
                     WHERE er.id = NEW.employment_record_id AND er.school_id = NEW.school_id;
                    IF v_employment_end IS NOT NULL AND v_employment_end < NEW.starts_on THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'employee_assignments: a placement is recorded as never effective only when its employment ended before it starts (hr_employee_assignments_never_effective)'
                    USING ERRCODE = 'check_violation';
            END;
            $$;
            CREATE TRIGGER trg_employee_assignments_never_effective
                BEFORE INSERT OR UPDATE OF ends_on ON employee_assignments
                FOR EACH ROW EXECUTE FUNCTION public.hr_employee_assignments_never_effective();
            SQL);
    }
};
