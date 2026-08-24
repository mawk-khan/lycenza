<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.2 -- the Employee's 1:N Restricted-tier emergency-contact
     * list (docs/modules/HR.md entity model: `EmployeeEmergencyContact
     * (1:N -- Restricted tier)`). An emergency contact is an external
     * person, never required to be a User/Guardian/Employee/other School
     * OS identity -- deliberately just `name`/`relationship`/contact
     * fields, no generic Party/Person subsystem introduced for this.
     * `relationship` is a plain, flexible string (not an enum) -- it is
     * never branched on by application logic, so freezing it into a
     * fixed set of code values would be exactly the kind of premature
     * enum CLAUDE.md rule 2 warns against.
     *
     * `phone` is required (an emergency contact with no way to reach
     * them serves no purpose); `alternate_phone`/`email` optional.
     * Neither phone field is format-constrained beyond a plain string --
     * matching every other phone field in this repo (`schools.phone`,
     * `campuses.phone`), deliberately not assuming a 10-digit Indian
     * number so School OS stays internationally usable.
     *
     * `is_primary` invariant: at most one primary contact per Employee,
     * enforced with a partial unique index
     * (`employee_emergency_contacts_one_primary_per_employee`), the same
     * pattern `academic_years_one_active_per_school` established --
     * App\Domain\HR\Application\EmployeeEmergencyContactService::setPrimary()
     * is the sole promotion path (demotes the previous primary and
     * promotes the new one in one transaction, mirroring
     * AcademicYearService::activate()'s exact demote-then-promote
     * shape), with this index as the database-level backstop against a
     * genuine concurrent race.
     *
     * `employee_id` composite-FKs to `employees(id, school_id)` (rule 70
     * pattern) with cascadeOnDelete, same reasoning as the other two
     * 8A.2 tables.
     */
    public function up(): void
    {
        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone');
            $table->string('alternate_phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('employee_id');
            $table->index('school_id');

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX employee_emergency_contacts_one_primary_per_employee '.
            'ON employee_emergency_contacts (school_id, employee_id) '.
            'WHERE is_primary = true'
        );

        TenantRls::enable('employee_emergency_contacts');
    }

    public function down(): void
    {
        TenantRls::disable('employee_emergency_contacts');
        Schema::dropIfExists('employee_emergency_contacts');
    }
};
