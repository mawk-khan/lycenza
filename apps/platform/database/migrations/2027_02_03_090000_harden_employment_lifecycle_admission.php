<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C2 (HR.R1-0 findings HRC-1, HRC-2).
 *
 * HRC-1 -- an employment never BEGINS with a planned end. `ends_on` is the
 * actual last day: the moment it is written the record is ended (the
 * eligibility guard stamps `ended_recorded_at` and freezes end and status).
 * An `active` (or draft / pre-joining / notice) row inserted with an
 * `ends_on` was therefore a dead end: never separable, never retention-
 * eligible. `retention_guard_end_record` (the existing guard; extended, not
 * replaced) now refuses, for the runtime role, an INSERT of an employment
 * whose `ends_on` is set without a terminal status -- the same rule its
 * UPDATE branch already applies ("a record ends only with a terminal
 * status"). The schema owner stays exempt (migrations, operator repair,
 * governed historical import). Student placements are unaffected.
 *
 * HRC-2 -- a placement that never took effect. Ending an employment closes
 * its assignments on the last day; an assignment that STARTS after the last
 * day (a future transfer or promotion) cannot end on that day without an
 * impossible range. It becomes the empty interval `ends_on = starts_on - 1`,
 * the convention HR.3A already uses for a reporting line cancelled before it
 * starts and ADR 0078 for a Student placement cancelled before it starts:
 * it never covers any day (every reader asks `starts_on <= d AND (ends_on IS
 * NULL OR ends_on >= d)`), its planned dates and its placement-change record
 * stay as history, and nothing is deleted. The CHECK admits exactly that one
 * extra shape, and `trg_employee_assignments_never_effective` admits it only
 * on an UPDATE of a placement starting after its employment's recorded end
 * (EmploymentService::end() is the one writer).
 *
 * Rollback refuses once any never-effective assignment exists (forward-fix).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employee_assignments DROP CONSTRAINT employee_assignments_date_range_check');
        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_date_range_check CHECK (ends_on IS NULL OR starts_on <= ends_on OR ends_on = starts_on - 1)');

        // The CHECK cannot tell writers apart; this can (second security review): the empty interval is admitted only
        // on an UPDATE of a placement that starts AFTER its employment's recorded end -- the disposition end() records
        // for a placement that never took effect. No other path can blank an effective placement's history.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_employee_assignments_never_effective() RETURNS trigger
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

            REVOKE ALL ON FUNCTION public.hr_employee_assignments_never_effective() FROM PUBLIC;
            SQL);

        DB::unprepared(self::guard(<<<'SQL'
                IF TG_OP = 'INSERT' THEN
                    -- HR.R1-C2 (HRC-1): an employment begins open; one inserted with an end must already be ended.
                    IF v_parent = 'employee' AND NEW.ends_on IS NOT NULL AND NEW.status NOT IN ('separated', 'terminated', 'retired', 'deceased') THEN
                        RAISE EXCEPTION '%: an employment never begins with a planned end -- it ends only through its end (retention_eligibility_guard)', TG_TABLE_NAME
                            USING ERRCODE = 'check_violation';
                    END IF;
                    NEW.ended_recorded_at := CASE WHEN NEW.ends_on IS NULL THEN NULL ELSE pg_catalog.now() AT TIME ZONE 'UTC' END;
                    RETURN NEW;
                END IF;
            SQL));
    }

    public function down(): void
    {
        if (DB::table('employee_assignments')->whereRaw('ends_on = starts_on - 1')->exists()) {
            throw new RuntimeException('Refusing to roll back: never-effective assignments are recorded (HR.R1-C2). Fix forward.');
        }

        DB::unprepared(self::guard(<<<'SQL'
                IF TG_OP = 'INSERT' THEN
                    NEW.ended_recorded_at := CASE WHEN NEW.ends_on IS NULL THEN NULL ELSE pg_catalog.now() AT TIME ZONE 'UTC' END;
                    RETURN NEW;
                END IF;
            SQL));

        DB::unprepared('DROP TRIGGER IF EXISTS trg_employee_assignments_never_effective ON employee_assignments; DROP FUNCTION IF EXISTS public.hr_employee_assignments_never_effective();');
        DB::statement('ALTER TABLE employee_assignments DROP CONSTRAINT employee_assignments_date_range_check');
        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_date_range_check CHECK (ends_on IS NULL OR starts_on <= ends_on)');
    }

    /** The guard as it stood before HR.R1-C2, with its INSERT branch replaced. */
    private static function guard(string $insertBranch): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION public.retention_guard_end_record() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_parent text := TG_ARGV[0];
                v_terminal boolean;
            BEGIN
                -- The schema owner (migrations, operator repair) is exempt: it owns the table.
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
            {$insertBranch}

                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                   OR (v_parent = 'employee' AND to_jsonb(NEW)->>'employee_id' IS DISTINCT FROM to_jsonb(OLD)->>'employee_id')
                   OR (v_parent = 'student' AND to_jsonb(NEW)->>'student_id' IS DISTINCT FROM to_jsonb(OLD)->>'student_id') THEN
                    RAISE EXCEPTION '%: the identity and start of a record are fixed (retention_eligibility_guard)', TG_TABLE_NAME;
                END IF;

                v_terminal := CASE WHEN v_parent = 'employee' THEN NEW.status IN ('separated', 'terminated', 'retired', 'deceased') ELSE NEW.status <> 'active' END;
                IF OLD.ends_on IS NOT NULL THEN
                    IF NEW.ends_on IS DISTINCT FROM OLD.ends_on OR NEW.status IS DISTINCT FROM OLD.status THEN
                        RAISE EXCEPTION '%: an ended record keeps its end and status (retention_eligibility_guard)', TG_TABLE_NAME;
                    END IF;
                ELSIF NEW.ends_on IS NOT NULL THEN
                    IF NOT v_terminal THEN
                        RAISE EXCEPTION '%: a record ends only with a terminal status (retention_eligibility_guard)', TG_TABLE_NAME;
                    END IF;
                ELSIF v_parent = 'employee' AND OLD.status IN ('separated', 'terminated', 'retired', 'deceased') AND NOT v_terminal THEN
                    RAISE EXCEPTION '%: a terminal record is never reopened (retention_eligibility_guard)', TG_TABLE_NAME;
                END IF;

                NEW.ended_recorded_at := CASE WHEN OLD.ends_on IS NULL AND NEW.ends_on IS NOT NULL THEN pg_catalog.now() AT TIME ZONE 'UTC' ELSE OLD.ended_recorded_at END;
                RETURN NEW;
            END;
            \$\$;
            SQL;
    }
};
