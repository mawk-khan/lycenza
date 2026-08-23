<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.3 -- HR's job/organizational-title reference data
     * (docs/modules/HR.md "Department and Position strategy"). A
     * Position (Teacher, Accountant, Principal, Librarian, Driver, ...)
     * is deliberately NOT Campus-scoped -- it is a School-wide job
     * title; which Campus an Assignment happens at (a later checkpoint,
     * 8A.4) is the Assignment's own concern, not the Position's.
     * Follows the exact `academic_departments` migration template:
     * `unique(school_id, code)` + `unique(id, school_id)` (so 8A.4's
     * EmployeeAssignment can composite-FK to it) + `TenantRls::enable()`.
     *
     * Position carries NO relationship whatsoever to `capabilities`/
     * `roles`/`membership_role_assignments` (docs/modules/HR.md
     * principle 2.4: Position != Authorization Role) -- this table has
     * no foreign key to any authorization table, and nothing in this
     * migration or App\Domain\HR\Application\PositionService creates,
     * references, or grants a Role/Capability as a side effect of
     * Position lifecycle. Granting actual application access remains
     * entirely Identity & Access's existing, separate mechanism.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        TenantRls::enable('positions');
    }

    public function down(): void
    {
        TenantRls::disable('positions');
        Schema::dropIfExists('positions');
    }
};
