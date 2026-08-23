<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: which capabilities a role grants. Roles
     * and capabilities are both platform catalog objects in Phase 0B
     * (no tenant-custom roles yet), so this join table is central too.
     */
    public function up(): void
    {
        Schema::create('role_capabilities', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('capability_key', 150);
            $table->foreign('capability_key')->references('key')->on('capabilities')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['role_id', 'capability_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_capabilities');
    }
};
