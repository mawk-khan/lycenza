<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected): a School-specific override of
     * a feature flag's global default. Absence of a row means "use the
     * flag's default_enabled" -- see App\Support\FeatureFlags\FeatureFlagResolver.
     */
    public function up(): void
    {
        Schema::create('feature_flag_school_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('feature_flag_key', 150);
            $table->foreign('feature_flag_key')->references('key')->on('feature_flags')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['school_id', 'feature_flag_key']);
        });

        TenantRls::enable('feature_flag_school_overrides');
    }

    public function down(): void
    {
        TenantRls::disable('feature_flag_school_overrides');
        Schema::dropIfExists('feature_flag_school_overrides');
    }
};
