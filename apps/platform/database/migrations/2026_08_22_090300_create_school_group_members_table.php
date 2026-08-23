<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: which Schools belong to which Group. This
     * is discovery/structural data only -- it grants no access by
     * itself. Cross-school access for a group admin is a separate,
     * explicit, audited authorization decision (platform_role_assignments
     * / a future group-scoped role), never inferred from this table.
     */
    public function up(): void
    {
        Schema::create('school_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_group_id')->constrained('school_groups')->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['school_group_id', 'school_id']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_group_members');
    }
};
