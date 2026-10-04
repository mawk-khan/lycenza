<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.2 (ADR 0066 §3, §5; ADR 0021 amendment): the HRX retention unit
 * moves from the migration/owner connection to the DEDICATED retention
 * identity `school_os_retention`.
 *
 * The role itself is cluster-level and carries a credential, so environment
 * provisioning creates it (Docker init 01-roles.sql, `ddev test` /
 * `ddev demo-reset`, CI, the production bootstrap), exactly like
 * `school_os_app`. This migration refuses to run if it is missing or not
 * narrow (LOGIN, NOSUPERUSER, NOBYPASSRLS, NOCREATEDB, NOCREATEROLE,
 * NOINHERIT, NOREPLICATION; a member of nothing; nothing a member of it).
 *
 * Grants, each demonstrated by the HRX unit (RetentionExpiry::privileged()
 * running LeaveEvidenceRetentionService / StaffAttendanceEvidenceRetentionService
 * through EmployeeRetentionEligibility::purgeSeparatedBefore()):
 * - EXECUTE on retention_expire_leave_employee_evidence and
 *   retention_expire_staff_attendance_employee_evidence: the destructive
 *   work, done with the owner's privileges by the definer function;
 * - column-level SELECT, read-only, for choosing the unit:
 *   - employees (id, school_id): the School's Employee walk (chunked by id)
 *     and the readSeparation() existence check;
 *   - employment_records (id, employee_id, status, ends_on): the separation
 *     resolution, and the join the Leave row filter uses;
 *   - leave_requests (employee_id), leave_policy_assignments,
 *     leave_ledger_entries, leave_year_close_items (employment_record_id):
 *     "Employees that still hold Leave rows" (LeaveEvidenceRetentionService);
 *   - staff_attendance_records (employee_id): the same for Staff Attendance;
 *   - retention_school_holds (school_id): the fail-closed hold-state check
 *     before a destructive run (RetentionExpiry::assertHoldStateCurrent()).
 * No INSERT, UPDATE, DELETE or TRUNCATE; no default privileges. Row locks
 * are taken only inside the definer functions (the PHP recheck is a plain
 * read), so no UPDATE privilege is needed for SELECT ... FOR UPDATE. Reads
 * stay subject to forced RLS (the role is not an owner and has no
 * BYPASSRLS).
 *
 * The shared HRX prologue (`retention_lock_hrx_employee`, inside both
 * functions) now authorizes EXACTLY the dedicated login: `session_user =
 * 'school_os_retention'`. `session_user` is the authenticated login; SET ROLE
 * cannot change it, and no membership or client setting satisfies the check.
 * The runtime role, the migration/owner role and any other login are
 * refused (`retention_privilege`). Every other check is unchanged.
 *
 * Rollback restores the RH.1 state exactly (the owner-membership prologue,
 * no grants to the retention role); the role itself is left to provisioning.
 */
return new class extends Migration
{
    private const ROLE = 'school_os_retention';

    /** @var array<string, string> function => signature */
    private const FUNCTIONS = [
        'retention_expire_leave_employee_evidence' => 'uuid, uuid, date, boolean',
        'retention_expire_staff_attendance_employee_evidence' => 'uuid, uuid, date, boolean',
    ];

    /** @var array<string, string> table => columns the HRX unit reads */
    private const SELECTS = [
        'employees' => 'id, school_id',
        'employment_records' => 'id, employee_id, status, ends_on',
        'leave_requests' => 'employee_id',
        'leave_policy_assignments' => 'employment_record_id',
        'leave_ledger_entries' => 'employment_record_id',
        'leave_year_close_items' => 'employment_record_id',
        'staff_attendance_records' => 'employee_id',
        'retention_school_holds' => 'school_id',
    ];

    public function up(): void
    {
        $role = DB::selectOne(
            'SELECT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolinherit AND NOT rolreplication AS narrow,
                    EXISTS (SELECT 1 FROM pg_auth_members m WHERE m.member = r.oid OR m.roleid = r.oid) AS shared
               FROM pg_roles r WHERE rolname = ?',
            [self::ROLE],
        );
        if ($role === null) {
            throw new RuntimeException('Provision the dedicated retention role school_os_retention first (01-roles.sql, ddev test/demo-reset, CI, or infrastructure/postgres/production-bootstrap.sql), then migrate.');
        }
        if (! $role->narrow || $role->shared) {
            throw new RuntimeException('school_os_retention is not narrow (needs LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION, and no role membership): fix it deliberately, then migrate.');
        }

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO ".self::ROLE);
        }
        foreach (self::SELECTS as $table => $columns) {
            DB::statement("GRANT SELECT ({$columns}) ON {$table} TO ".self::ROLE);
        }

        $this->prologue("session_user = 'school_os_retention'::name");
    }

    public function down(): void
    {
        // The RH.1 prologue (2026_11_24_090000), byte for byte.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION retention_lock_hrx_employee(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS uuid[]
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_record uuid;
            BEGIN
                -- The authorized retention identity: the session (login) user must hold the
                -- HR tables' owner privileges. The runtime role never does.
                IF NOT pg_catalog.pg_has_role(session_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = 'public.employees'::regclass), 'MEMBER') THEN
                    RAISE EXCEPTION 'retention: not the authorized retention identity (retention_privilege)';
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                -- The E21 legal hold, at the destructive boundary.
                IF EXISTS (SELECT 1 FROM public.retention_school_holds WHERE school_id = p_school_id) THEN
                    RAISE EXCEPTION 'retention: the School is under a retention hold (retention_hold)';
                END IF;
                PERFORM public.retention_assert_payroll_employee_floor(p_school_id, p_employee_id, p_cutoff);
                SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_records
                  FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id;
                FOREACH v_record IN ARRAY v_records LOOP
                    PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('hrx.staff_employment:' || p_school_id::text || ':' || v_record::text, 0));
                END LOOP;
                RETURN v_records;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_lock_hrx_employee(uuid, uuid, date) FROM PUBLIC;
            SQL);

        foreach (self::SELECTS as $table => $columns) {
            DB::statement("REVOKE SELECT ({$columns}) ON {$table} FROM ".self::ROLE);
        }
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$signature}) FROM ".self::ROLE);
        }
    }

    /** The HRX prologue (2026_11_24_090000), with $authorized as its identity check. */
    private function prologue(string $authorized): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION retention_lock_hrx_employee(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS uuid[]
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_records uuid[];
                v_record uuid;
            BEGIN
                -- The authorized retention identity, checked on the authenticated login (session_user).
                IF NOT ({$authorized}) THEN
                    RAISE EXCEPTION 'retention: not the authorized retention identity (retention_privilege)';
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                -- The E21 legal hold, at the destructive boundary.
                IF EXISTS (SELECT 1 FROM public.retention_school_holds WHERE school_id = p_school_id) THEN
                    RAISE EXCEPTION 'retention: the School is under a retention hold (retention_hold)';
                END IF;
                PERFORM public.retention_assert_payroll_employee_floor(p_school_id, p_employee_id, p_cutoff);
                SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_records
                  FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id;
                FOREACH v_record IN ARRAY v_records LOOP
                    PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('hrx.staff_employment:' || p_school_id::text || ':' || v_record::text, 0));
                END LOOP;
                RETURN v_records;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_lock_hrx_employee(uuid, uuid, date) FROM PUBLIC;
            SQL);
    }
};
