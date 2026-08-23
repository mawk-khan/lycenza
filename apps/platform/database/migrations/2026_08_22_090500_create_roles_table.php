<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: the role catalog. Roles are convenience
     * bundles of capabilities (docs/security/AUTHORIZATION.md) -- the
     * enforcement point is always a capability check, never a role
     * name. `scope` separates platform roles (assignable only via
     * platform_role_assignments) from school roles (assignable only
     * via membership_role_assignments) -- enforced by a DB trigger on
     * each assignment table, not just convention. Only system-defined
     * roles exist in Phase 0B; tenant-custom roles are future work.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique(); // e.g. "school_admin"
            $table->string('name');
            $table->string('scope'); // platform|school
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
