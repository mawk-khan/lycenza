<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.2 -- the Employee's 1:1 Restricted-tier personal-details
     * extension (docs/modules/HR.md entity model: `EmployeePersonalDetail
     * (1:1 -- Restricted tier)`; privacy classification matrix: DOB and
     * personal contact info are Sensitive/Restricted, gated by
     * `hr.employees.personal.*` once 8A.10 wires up authorization).
     * Deliberately carries no government identifiers, bank details, tax
     * declarations, or health data -- those stay Highly Sensitive and
     * unmodeled in Phase 8A per HR.md's authorization design.
     *
     * `unique('employee_id')` is the 1:1 backstop, the same single-
     * column-unique-on-the-parent-FK pattern `hr_employee_number_counters`
     * already established for its own School-scoped 1:1 relationship --
     * App\Domain\HR\Application\EmployeePersonalDetailService is the
     * sole write path and enforces create-if-absent/update-otherwise
     * via `updateOrCreate()`, with this constraint as the database-level
     * guarantee against a genuine race producing two rows for the same
     * Employee.
     *
     * `employee_id` composite-FKs to `employees(id, school_id)` (rule 70
     * pattern, exactly like `academic_terms` -> `academic_years`) --
     * cascadeOnDelete because this row has no meaning independent of its
     * owning Employee (Employee itself has no delete endpoint --
     * archival via `record_status`, not deletion -- so this is a
     * defensive/structural choice, not an expected runtime path).
     */
    public function up(): void
    {
        Schema::create('employee_personal_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('preferred_language')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('personal_phone')->nullable();
            $table->string('alternate_phone')->nullable();
            $table->timestamps();

            $table->unique('employee_id');
            $table->index('school_id');

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        TenantRls::enable('employee_personal_details');
    }

    public function down(): void
    {
        TenantRls::disable('employee_personal_details');
        Schema::dropIfExists('employee_personal_details');
    }
};
