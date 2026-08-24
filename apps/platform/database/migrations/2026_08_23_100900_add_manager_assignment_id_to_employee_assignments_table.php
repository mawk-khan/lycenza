<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.5 -- Reporting Hierarchy. docs/modules/HR.md's
     * "Reporting hierarchy strategy" locked this decision in at 8A.0
     * and it has been reconfirmed at every checkpoint since (8A.3's
     * Department-hierarchy docs, 8A.4's own migration docblock):
     * `employee_assignments.manager_assignment_id`, a nullable,
     * self-referencing, same-School composite FK -- NOT a separate
     * `employee_reporting_lines` table. 8A.4 deliberately added
     * `unique(id, school_id)` to `employee_assignments` specifically so
     * this migration could add this exact composite self-FK cleanly,
     * without reshaping that table's keys.
     *
     * Rationale (from HR.md, unchanged): a bare Employee-level manager
     * pointer cannot represent an Employee holding two Assignments
     * (Teacher, Coordinator) with two different managers. Pointing at
     * the manager's specific Assignment row gives each Assignment its
     * own manager, which is what "reports to for this specific
     * capacity" actually means.
     *
     * Historical preservation of manager CHANGES follows the exact
     * same principle already established for Position/Department/
     * Campus changes (docs/modules/HR.md / 8A.4 "Transfer semantics"):
     * `manager_assignment_id` is a live, directly-updatable field on an
     * open Assignment (not a date-ranged column itself) --
     * App\Domain\HR\Application\ReportingHierarchyService::setManager()
     * is the sole write path, and every change is fully audited
     * (`hr.assignment.manager_changed`, before/after ids) -- the audit
     * trail is where "who reported to whom, when" history lives,
     * exactly like every other mutable HR field in this module (rule
     * 11: audit is append-only; corrections are new records, not a
     * second live table). This checkpoint does NOT support scheduling
     * a manager change for a future effective date in advance, or
     * true simultaneous dotted-line/multi-manager reporting for one
     * Assignment (a single nullable FK column has exactly one slot) --
     * both are honest, documented consequences of the committed
     * single-column design, not implemented, not silently pretended.
     *
     * Self-report prevention: a CHECK constraint (deterministic,
     * database-enforced) mirrors `hr_departments_no_self_parent_check`
     * exactly. Indirect cycles (A -> B -> C -> A) cannot be expressed
     * as a CHECK constraint (no recursion) -- rejected at the
     * application layer by
     * ReportingHierarchyService::assertNoCycle(), a deterministic,
     * bounded ancestry walk (each Assignment has at most one manager),
     * called only after both involved Assignment rows are locked in
     * deterministic ascending-id order inside the same transaction --
     * this is what makes a concurrent A->B / B->A race safe (see
     * ReportingHierarchyService's own docblock for the full reasoning
     * and the real two-process concurrency proof).
     *
     * `nullOnDelete()` -- same reasoning as `hr_departments.parent_department_id`
     * (8A.3): Assignment rows have no delete endpoint (this FK is a
     * defensive/structural backstop, not an expected runtime path);
     * losing a manager Assignment row should clear the dangling
     * pointer, not block the deletion or cascade it away.
     */
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->uuid('manager_assignment_id')->nullable()->after('position_id');
            $table->index('manager_assignment_id');

            $table->foreign(['manager_assignment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_assignments')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_no_self_report_check CHECK (manager_assignment_id IS NULL OR manager_assignment_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employee_assignments DROP CONSTRAINT IF EXISTS employee_assignments_no_self_report_check');

        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropForeign(['manager_assignment_id', 'school_id']);
            $table->dropIndex(['manager_assignment_id']);
            $table->dropColumn('manager_assignment_id');
        });
    }
};
