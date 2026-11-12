<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C26 (WO-11, C12-N2, R1-01 point 4, `docs/hr/HR-R1-C25-R1-01-DECISION.md` §9): the runtime role
 * holds only the privileges its named operations need. Departments, Positions and Employee Categories are
 * deactivated through their `status`, never deleted, and the Employee number counter only moves forward;
 * nothing in the application deletes from them, yet `school_os_app` held DELETE on all four through the
 * schema's default privileges. This revokes exactly that.
 *
 * - SELECT, INSERT and UPDATE stay: the owning services (`DepartmentService`, `PositionService`,
 *   `EmployeeCategoryService`, `EmployeeNumberAllocator`) create and update these rows.
 * - Rows still leave with their School: the `school_id` foreign keys cascade as the table owner, and the
 *   runtime role can never delete a School (rule 86). No retention function deletes these tables
 *   (`TenantRetentionCatalog` `hr_configuration`: tenant lifetime).
 * - `platform:verify-database` pins the absence (`DatabaseRoleVerifier::NO_RUNTIME_DELETE`).
 *
 * `down()` restores the grant this migration removed, which is the schema as it stood before. A schema
 * rolled back this far therefore fails `runtime_destructive_privileges_restricted` until it migrates
 * forward again: rollback is local and testing only (rule 143), never a production correction.
 */
return new class extends Migration
{
    /** The four C12-N2 tables (`HR-R1-0-AUDIT-REGISTER.md`), never a wildcard. */
    private const TABLES = ['hr_departments', 'positions', 'employee_categories', 'hr_employee_number_counters'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("REVOKE DELETE ON {$table} FROM school_os_app");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("GRANT DELETE ON {$table} TO school_os_app");
        }
    }
};
