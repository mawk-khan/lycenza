<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C28 (C28-N2, WO-13): every guard function of the HR family pins its search_path, with pg_temp last.
 *
 * These 40 INVOKER trigger functions name tables without a schema and set no search_path, so they resolved
 * relations through the caller's path, where PostgreSQL searches pg_temp first unless it is placed last. The
 * runtime role holds TEMP (the database's default grant to PUBLIC), so a session running raw SQL as
 * school_os_app could create a temporary table with a guarded table's name and the guard read it instead:
 * reproduced against leave_types_freeze (GuardSearchPathShadowTest). The C22 guards already pin
 * pg_catalog, pg_temp for this reason.
 *
 * - The path is pg_catalog, public, pg_temp: every object these bodies name lives in public, so behaviour is
 *   unchanged; only the temporary schema moves to the end, where it can no longer shadow anything.
 * - Bodies, security mode, owner and grants are unchanged (no CREATE OR REPLACE), so prosrc hashes pinned by
 *   platform:verify-database stay valid.
 * - platform:verify-database pins it (hr_family_trigger_functions).
 *
 * down() resets the setting this migration added: the schema as it stood before. Rollback is local and
 * testing only (rule 143), and a rolled-back schema fails hr_family_trigger_functions.
 */
return new class extends Migration
{
    /** The HR family's INVOKER guard functions that had no search_path (catalogue, 2026-11-12). */
    private const FUNCTIONS = [
        'hr_departments_no_cycle',
        'hr_employee_assignments_primary_no_overlap',
        'hr_employee_documents_guard',
        'hr_employee_placement_changes_guard',
        'hr_employee_reference_codes_guard',
        'hr_employees_identity_guard',
        'hr_employment_records_initial_terms',
        'hr_employment_records_no_overlap',
        'hr_employment_records_reporting_bounds',
        'hr_employment_records_separation_notice',
        'hr_employment_records_terms_projection_guard',
        'hr_employment_separation_cases_guard',
        'hr_employment_terms_append_guard',
        'hr_employment_terms_project',
        'hr_history_row_immutable',
        'hr_profile_record_archive_guard',
        'hr_reporting_relationships_guard',
        'hr_separation_clearance_items_guard',
        'hr_separation_clearance_requirements_guard',
        'hr_workforce_task_events_guard',
        'hr_workforce_tasks_guard',
        'leave_accrual_run_items_guard',
        'leave_accrual_runs_guard',
        'leave_decisions_guard',
        'leave_eligibility_rules_guard',
        'leave_eligibility_runs_immutable',
        'leave_ledger_guard',
        'leave_policies_immutable',
        'leave_policies_rules_complete',
        'leave_policy_assignments_accrual_bounds',
        'leave_policy_assignments_guard',
        'leave_request_days_guard',
        'leave_requests_guard',
        'leave_settings_lock',
        'leave_types_freeze',
        'leave_year_start_changes_guard',
        'leave_years_guard',
        'staff_attendance_corrections_applied',
        'staff_attendance_corrections_guard',
        'staff_attendance_records_guard',
    ];

    public function up(): void
    {
        foreach (self::FUNCTIONS as $function) {
            DB::statement("ALTER FUNCTION public.{$function}() SET search_path = pg_catalog, public, pg_temp");
        }
    }

    public function down(): void
    {
        foreach (self::FUNCTIONS as $function) {
            DB::statement("ALTER FUNCTION public.{$function}() RESET search_path");
        }
    }
};
