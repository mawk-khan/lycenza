<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A closure correction ("EmployeeCategory"). One of the 14
     * entities the original 8A.0 entity model named (`EmployeeCategory
     * -- School-configurable classification, e.g. Teaching/Non-
     * Teaching/Contract/Visiting -- reference data, not a hardcoded
     * enum`), deferred at 8A.3 and never built. Follows the exact
     * `positions` migration template this checkpoint's own Reuse
     * Decisions table already committed to
     * ("`NormalizesCode`/`NormalizesCodeInput` REUSE... `Department.code`,
     * `Position.code`, `EmployeeCategory.code` follow the exact
     * Campus/AcademicDepartment convention"): `unique(school_id, code)`
     * + `unique(id, school_id)` (composite-FK target) +
     * `TenantRls::enable()`. School-wide reference data, not Campus-
     * scoped -- the plan's own entity-model diagram places
     * `EmployeeCategory` as a School-level sibling of `HR Department`/
     * `Position`, never nested under an Assignment's own Campus
     * dimension.
     *
     * FK linkage point: the original plan named this entity and its
     * purpose but never specified exactly which row references it.
     * This correction attaches it to `employment_records` (nullable
     * `employee_category_id`), not `employees` -- a classification
     * like "Contract" vs. "Permanent" describes the nature of one
     * engagement and can genuinely change across a rehire (e.g. a
     * Visiting-category Employment followed by a Teaching-category
     * rehire for the SAME Employee), matching how `employment_type`/
     * `status` already live on `employment_records` rather than
     * `employees` for the identical reason (docs/modules/HR.md
     * "Employee lifecycle -- state responsibility matrix").
     */
    public function up(): void
    {
        Schema::create('employee_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        TenantRls::enable('employee_categories');
    }

    public function down(): void
    {
        TenantRls::disable('employee_categories');
        Schema::dropIfExists('employee_categories');
    }
};
