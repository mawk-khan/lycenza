<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.5 (ADR 0066 §3.2, §9, §13): the Payroll and LMS retention units
 * move WHOLE to the dedicated retention identity. Finance, Student and
 * Guardian (RH.6) are NOT touched.
 *
 * Functions (`retention_expire_payroll_employee_evidence`,
 * `retention_expire_payroll_run`, `retention_expire_learning_content`,
 * `retention_expire_assignment`):
 * - EXECUTE moves from the runtime role to `school_os_retention` (PUBLIC
 *   stays revoked; owner and SECURITY DEFINER unchanged);
 * - a prologue is injected right after BEGIN, before every existing check:
 *   - `retention_assert_retention_identity()` (E21-RH.4): the authenticated
 *     login must be exactly `school_os_retention`;
 *   - `retention_assert_not_held(p_school_id)` (E21-RH.3) UNCONDITIONALLY,
 *     dry runs included, as HRX does: these are unit functions whose dry
 *     run validates the unit inside the destructive transaction, so the
 *     shared hold lock is taken by the first check and held to commit, and
 *     a held unit is kept (never half-validated).
 *   Every existing floor, lock, dependency check, freeze-guard interaction
 *   and School predicate is left byte for byte as it was.
 *
 * `retention_expire_lms_resource(kind, school, id, cutoff)` (new, SECURITY
 * DEFINER, retention identity only) is the whole destructive LMS unit, so the
 * retention identity never needs DELETE on `documents`: it locks the
 * resource (a concurrent Document attach waits, then fails on its foreign
 * key), deletes the resource's Document rows, then calls the LMS function
 * above, which re-proves the tenant, the floor, the Academic Year, the D6
 * authority and that no Document is left, and deletes the audiences and the
 * resource. Any refusal rolls the Document deletion back with it. It returns
 * the deleted Documents' storage locations, for byte deletion after commit.
 *
 * The PHP units now run wholly on `pgsql_retention`; their rechecks are
 * plain reads (the functions take every row lock themselves), so the role
 * gets column-level SELECT on exactly what the units read (SELECTS) and no
 * write privilege.
 *
 * Rollback removes exactly the injected prologue, the new function, the
 * retention EXECUTE grants and these column SELECTs. It deliberately does
 * NOT re-grant EXECUTE to the runtime role (an unsafe privilege is never
 * restored by a rollback): after a rollback only the owner can run them, so
 * Payroll and LMS retention fail closed until migrated again.
 */
return new class extends Migration
{
    private const FUNCTIONS = [
        'retention_expire_payroll_employee_evidence',
        'retention_expire_payroll_run',
        'retention_expire_learning_content',
        'retention_expire_assignment',
    ];

    private const ROLE = 'school_os_retention';

    /**
     * @var array<string, string> table => the columns the moved PHP units read (on top of E21-RH.2's
     *                            employees(id, school_id) and employment_records(id, employee_id, status, ends_on))
     */
    private const SELECTS = [
        // Payroll evidence: the Employees with evidence (EmployeeRetentionEligibility + PayrollEvidenceRetentionService).
        'payroll_run_results' => 'employee_id, payroll_run_id',
        'payroll_adjustments' => 'employment_record_id',
        'payroll_lwf_annual_charges' => 'employment_record_id',
        // Payroll runs: the emptied, posted regular runs, and the plain recheck.
        'payroll_runs' => 'id, school_id, run_kind, status, results_expired_at, posted_at',
        // LMS: the resources of ended years, their Documents and the D6 owner authority (LmsResourceRetention).
        'learning_content' => 'id, school_id, subject_offering_id, owner_employee_id',
        'assignments' => 'id, school_id, subject_offering_id, owner_employee_id',
        'subject_offerings' => 'id, school_id, academic_year_id',
        'academic_years' => 'id, school_id, ends_on',
        'documents' => 'id, learning_content_id, assignment_id',
        'learning_content_section_audiences' => 'school_id, learning_content_id, section_id',
        'assignment_section_audiences' => 'school_id, assignment_id, section_id',
        'teaching_assignments' => 'school_id, employee_id, subject_offering_id, section_id, ends_on',
    ];

    public function up(): void
    {
        foreach (self::FUNCTIONS as $function) {
            $definition = $this->definition($function);
            if (substr_count($definition, "\nBEGIN\n") !== 1 || str_contains($definition, 'retention_assert_retention_identity')) {
                throw new RuntimeException("{$function}: unexpected body shape; refusing to inject the RH.5 prologue.");
            }
            DB::unprepared(str_replace("\nBEGIN\n", "\nBEGIN\n".self::prologue(), $definition));
            $this->grantToRetentionOnly($function);
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION retention_expire_lms_resource(p_kind text, p_school_id uuid, p_id uuid, p_cutoff date)
                RETURNS TABLE (o_disk text, o_path text)
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_disks text[];
                v_paths text[];
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                PERFORM public.retention_assert_not_held(p_school_id);
                PERFORM public.retention_assert_tenant(p_school_id);
                -- Lock the resource first: a concurrent Document attach (FOR KEY SHARE on it) waits, then fails on its FK.
                IF p_kind = 'learning_content' THEN
                    PERFORM 1 FROM public.learning_content WHERE school_id = p_school_id AND id = p_id FOR UPDATE;
                    WITH d AS (DELETE FROM public.documents WHERE school_id = p_school_id AND learning_content_id = p_id
                               RETURNING storage_disk::text AS sdisk, storage_path::text AS spath)
                    SELECT coalesce(array_agg(d.sdisk ORDER BY d.spath), '{}'), coalesce(array_agg(d.spath ORDER BY d.spath), '{}') INTO v_disks, v_paths FROM d;
                    -- Re-proves tenant, floor, year, D6 authority and that no Document is left; deletes audiences and resource.
                    PERFORM public.retention_expire_learning_content(p_school_id, p_id, p_cutoff, false);
                ELSIF p_kind = 'assignment' THEN
                    PERFORM 1 FROM public.assignments WHERE school_id = p_school_id AND id = p_id FOR UPDATE;
                    WITH d AS (DELETE FROM public.documents WHERE school_id = p_school_id AND assignment_id = p_id
                               RETURNING storage_disk::text AS sdisk, storage_path::text AS spath)
                    SELECT coalesce(array_agg(d.sdisk ORDER BY d.spath), '{}'), coalesce(array_agg(d.spath ORDER BY d.spath), '{}') INTO v_disks, v_paths FROM d;
                    PERFORM public.retention_expire_assignment(p_school_id, p_id, p_cutoff, false);
                ELSE
                    RAISE EXCEPTION 'retention: not an LMS resource kind (retention_lms)';
                END IF;
                RETURN QUERY SELECT u.sdisk, u.spath FROM unnest(v_disks, v_paths) AS u(sdisk, spath);
            END;
            $$;
            SQL);
        $this->grantToRetentionOnly('retention_expire_lms_resource');

        foreach (self::SELECTS as $table => $columns) {
            DB::statement("GRANT SELECT ({$columns}) ON {$table} TO ".self::ROLE);
        }
    }

    public function down(): void
    {
        foreach (self::SELECTS as $table => $columns) {
            DB::statement("REVOKE SELECT ({$columns}) ON {$table} FROM ".self::ROLE);
        }
        DB::statement('DROP FUNCTION retention_expire_lms_resource(text, uuid, uuid, date)');

        foreach (self::FUNCTIONS as $function) {
            $definition = $this->definition($function);
            $injected = "\nBEGIN\n".self::prologue();
            if (substr_count($definition, $injected) !== 1) {
                throw new RuntimeException("{$function}: the RH.5 prologue is not present exactly once; refusing to roll back.");
            }
            DB::unprepared(str_replace($injected, "\nBEGIN\n", $definition));

            // Deliberately no re-grant to the runtime role (see the class docblock).
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$this->signature($function)}) FROM ".self::ROLE);
        }
    }

    private static function prologue(): string
    {
        return "    -- E21-RH.5 (ADR 0066): only the retention identity; nothing, not even a dry-run validation, while a retention hold is active.\n"
            ."    PERFORM public.retention_assert_retention_identity();\n"
            ."    PERFORM public.retention_assert_not_held(p_school_id);\n";
    }

    private function grantToRetentionOnly(string $function): void
    {
        $signature = $this->signature($function);
        DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
        DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$signature}) FROM school_os_app");
        DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO ".self::ROLE);
    }

    private function definition(string $function): string
    {
        return (string) DB::selectOne(
            "SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->d;
    }

    private function signature(string $function): string
    {
        return (string) DB::selectOne(
            "SELECT pg_get_function_identity_arguments(p.oid) AS s FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->s;
    }
};
