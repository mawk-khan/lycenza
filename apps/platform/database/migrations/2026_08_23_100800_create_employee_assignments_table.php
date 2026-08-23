<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.4 -- where/how an Employee works during one
     * EmploymentRecord (docs/modules/HR.md canonical terminology:
     * `Assignment`; entity model: `EmploymentRecord -> EmployeeAssignment
     * (1:N per Employment) -> Campus (nullable) / HR Department
     * (nullable) / Position (required)`).
     *
     * Deliberately has NO `employee_id` column -- the owning Employee
     * is always reached through `employment_record_id`, never
     * duplicated (docs/modules/HR.md's entity model draws
     * EmployeeAssignment as a child of EmploymentRecord, not of
     * Employee directly; normalizing avoids a second, independently-
     * mutable copy of "which Employee does this belong to" that would
     * need its own consistency-with-employment_record_id invariant).
     * This also makes the IDOR-shaped "EmploymentRecord belongs to
     * Employee A but an Assignment ends up under Employee B" scenario
     * structurally impossible, not just tested-against: there is no
     * second employee-identifying column that could ever disagree with
     * `employment_record_id`'s own owner.
     *
     * Deliberately has NO `status` column -- docs/modules/HR.md's
     * "Employee lifecycle" state matrix is explicit: "Assignment
     * status: Derived from starts_on/ends_on (no separate status
     * column) -- 'current' = starts_on <= today <= (ends_on OR
     * infinity)." App\Domain\HR\Infrastructure\EmployeeAssignment::isCurrent()
     * computes this; nothing is stored.
     *
     * Deliberately has NO `manager_assignment_id` yet -- HR.md's
     * "Reporting hierarchy strategy" already locked in that exact
     * column name (self-referencing, same-School composite FK) as the
     * 8A.5 design, but 8A.5 owns its implementation. `unique(id,
     * school_id)` below exists specifically so 8A.5 can add that
     * self-referencing composite FK cleanly without an additional
     * migration reshaping this table's keys.
     *
     * `campus_id`/`department_id` nullable (School-wide assignment
     * when null), `position_id` required -- exactly HR.md's entity
     * model. All three composite-FK to their tenant-safe `(id,
     * school_id)` targets (rule 70) with `restrictOnDelete()` -- never
     * `cascadeOnDelete()` -- because Campus/Department/Position are
     * shared reference data with no delete endpoint of their own
     * (rule 73); a hard delete of any of them must never silently
     * destroy Assignment history. `employment_record_id` uses
     * `cascadeOnDelete()` instead, because unlike Campus/Department/
     * Position (referenced by many unrelated Assignments across many
     * Employees), an EmploymentRecord is Assignment's true, exclusive
     * owning parent -- the same distinction 8A.2 already drew between
     * `employee_id` (cascadeOnDelete, true parent) and campus/parent-
     * department (nullOnDelete, shared reference) on `hr_departments`.
     *
     * Department/Campus compatibility (docs/modules/HR.md "Department
     * and Position strategy": `campus_id` nullable on `hr_departments`
     * -- null = School-wide, non-null = Campus-scoped) is NOT database-
     * constrained here: whether a given (campus_id, department_id)
     * pair on an Assignment is compatible depends on comparing against
     * `hr_departments.campus_id`, a property of a DIFFERENT, mutable
     * row -- a plain FK cannot express that. Enforced at the
     * application layer by
     * App\Domain\HR\Application\EmployeeAssignmentService::create().
     *
     * `is_primary`: at most one currently-open (`ends_on IS NULL`)
     * primary Assignment per EmploymentRecord, enforced with the exact
     * partial unique index HR.md's "Temporal data strategy" already
     * named (`employee_assignments_one_primary_open_per_employment`)
     * -- deliberately scoped to OPEN rows only, so a historical
     * (ended) primary Assignment never blocks a later Employment's own
     * primary Assignment.
     *
     * Assignment-within-Employment date containment (the Assignment's
     * `[starts_on, ends_on-or-open]` interval must fall within its
     * owning Employment's own interval) is application-validated in
     * App\Domain\HR\Application\EmployeeAssignmentService::create(),
     * not database-constrained -- HR.md's own "Database constraints"
     * table already decided this explicitly ("Assignment falls within
     * owning Employment's date range | Application-level validation in
     * 8A.4").
     */
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('campus_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id');
            $table->boolean('is_primary')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index('employment_record_id');
            $table->index('campus_id');
            $table->index('department_id');
            $table->index('position_id');

            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['department_id', 'school_id'])
                ->references(['id', 'school_id'])->on('hr_departments')
                ->restrictOnDelete();

            $table->foreign(['position_id', 'school_id'])
                ->references(['id', 'school_id'])->on('positions')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_date_range_check CHECK (ends_on IS NULL OR starts_on <= ends_on)');

        DB::statement(
            'CREATE UNIQUE INDEX employee_assignments_one_primary_open_per_employment '.
            'ON employee_assignments (employment_record_id) '.
            'WHERE is_primary = true AND ends_on IS NULL'
        );

        TenantRls::enable('employee_assignments');
    }

    public function down(): void
    {
        TenantRls::disable('employee_assignments');
        Schema::dropIfExists('employee_assignments');
    }
};
