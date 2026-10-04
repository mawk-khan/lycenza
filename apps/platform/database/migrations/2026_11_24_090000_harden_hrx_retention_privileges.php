<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6 hardening (ADR 0065 §27.10): the HRX purge primitives become a
 * privilege boundary of their own, not an application convention.
 *
 * Before: the runtime role (`school_os_app`) held EXECUTE on
 * `retention_expire_leave_employee_evidence` and
 * `retention_expire_staff_attendance_employee_evidence` (the E21.2B
 * pattern). Both are SECURITY DEFINER, so they delete with the owner's
 * privileges: the runtime role's missing DELETE grant did not stop a direct
 * `SELECT retention_expire_...(...)`, and the legal hold
 * (RETENTION_HOLD_SCHOOL_IDS) was checked only in PHP.
 *
 * After:
 * - the runtime role has NO EXECUTE on either function (nor on the
 *   prologue helper); the retention run calls them on the existing
 *   migration/owner connection (`pgsql_admin`, rule 54) -- the authorized
 *   retention execution identity -- never on a request connection;
 * - the shared prologue (`retention_lock_hrx_employee`, run inside both
 *   functions) refuses unless the SESSION user (unforgeable: SET ROLE
 *   changes current_user, never session_user) is a member of the owner of
 *   the HR tables (`retention_privilege`). Even a future accidental EXECUTE
 *   grant to the runtime role cannot pass it;
 * - `retention_school_holds`: the database side of the existing E21 legal
 *   hold. The privileged retention path mirrors RETENTION_HOLD_SCHOOL_IDS
 *   into it (`RetentionHolds::synchronize()`) before it purges; the
 *   prologue refuses any held School (`retention_hold`), so a direct call
 *   by an otherwise authorized caller cannot bypass the hold. The runtime
 *   role has no privilege on the table (it can neither place nor release a
 *   hold);
 * - every existing check stays: tenant, 8-year floor, final separation with
 *   the Employee and EmploymentRecords locked, the HRX locks, row age,
 *   causal order, the remaining-rows proof.
 *
 * Rollback restores the HRX.6 state exactly (helper body, runtime EXECUTE)
 * and drops the hold mirror; it refuses while a hold is recorded, because
 * dropping it would silently release a legal hold at the database boundary.
 */
return new class extends Migration
{
    /** @var array<string, string> function => signature */
    private const FUNCTIONS = [
        'retention_expire_leave_employee_evidence' => 'uuid, uuid, date, boolean',
        'retention_expire_staff_attendance_employee_evidence' => 'uuid, uuid, date, boolean',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE retention_school_holds (
                school_id uuid PRIMARY KEY REFERENCES schools (id) ON DELETE RESTRICT,
                source varchar(16) NOT NULL DEFAULT 'config' CONSTRAINT retention_school_holds_source_check CHECK (source IN ('config')),
                held_since timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC')
            );
            REVOKE ALL ON retention_school_holds FROM PUBLIC;
            REVOKE ALL ON retention_school_holds FROM school_os_app;

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

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$signature}) FROM school_os_app");
        }
    }

    public function down(): void
    {
        if (DB::table('retention_school_holds')->exists()) {
            throw new RuntimeException('Refusing to roll back: retention_school_holds records an active retention hold; dropping it would release the hold at the database boundary.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION retention_lock_hrx_employee(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS uuid[]
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_record uuid;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
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
            DROP TABLE retention_school_holds;
            SQL);

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO school_os_app");
        }
    }
};
