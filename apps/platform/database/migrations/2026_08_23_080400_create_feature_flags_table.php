<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform catalog (like `capabilities`/`roles`): the set
     * of flags that exist and their global default. Not RLS-protected
     * -- flag DEFINITIONS are platform-wide; per-School overrides live
     * in feature_flag_school_overrides (tenant-owned).
     */
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->string('key')->primary(); // e.g. "ai.concierge"
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('default_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
