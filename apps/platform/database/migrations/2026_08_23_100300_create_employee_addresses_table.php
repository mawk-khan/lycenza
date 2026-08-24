<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.2 -- the Employee's 1:N Restricted-tier address book
     * (docs/modules/HR.md entity model: `EmployeeAddress (1:N --
     * Restricted tier)`). Field naming (`address_line1`/`address_line2`/
     * `city`/`state_region`/`postal_code`/`country_code`) is a direct
     * copy of `schools`'/`add_profile_fields_to_schools_table`'s
     * established convention, not a new shape invented for HR --
     * `country_code` is a raw ISO 3166-1 alpha-2 string (no `countries`
     * reference table exists in this repo, matching `schools.country_code`
     * exactly, default 'IN' for the same reason). No locality/district
     * columns -- `schools`' own address shape doesn't have them either,
     * and adding richer normalization here without a driving requirement
     * would be exactly the over-normalization CLAUDE.md rule 2 warns
     * against.
     *
     * `address_type` (`current`/`permanent`/`mailing`/`other`) is a
     * plain, application-validated string -- matching the established
     * `record_status`/`academic_years.status` convention of NOT
     * database-CHECK-constraining this kind of column.
     *
     * Address invariant, decided here since HR.md is silent on the exact
     * cardinality: an Employee may have AT MOST ONE address of each of
     * `current`/`permanent`/`mailing`, but arbitrarily many `other`
     * addresses -- enforced with a partial unique index
     * (`employee_addresses_one_per_type_per_employee`), the same
     * database-enforced pattern `academic_years_one_active_per_school`
     * already established, rather than adding a separate `is_primary`
     * boolean alongside the type enum (which would just duplicate the
     * same invariant through a second, redundant mechanism).
     *
     * `employee_id` composite-FKs to `employees(id, school_id)` (rule 70
     * pattern) with cascadeOnDelete, same reasoning as
     * `employee_personal_details`.
     */
    public function up(): void
    {
        Schema::create('employee_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('address_type'); // current|permanent|mailing|other
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('IN');
            $table->timestamps();

            $table->index('employee_id');
            $table->index('school_id');

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX employee_addresses_one_per_type_per_employee '.
            'ON employee_addresses (school_id, employee_id, address_type) '.
            "WHERE address_type <> 'other'"
        );

        TenantRls::enable('employee_addresses');
    }

    public function down(): void
    {
        TenantRls::disable('employee_addresses');
        Schema::dropIfExists('employee_addresses');
    }
};
