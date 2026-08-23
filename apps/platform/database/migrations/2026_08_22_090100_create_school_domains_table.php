<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: trusted domain->School routing table used
     * by tenant resolution (docs/architecture/TENANCY.md, "School
     * domain resolution"). No RLS -- resolving a domain must be
     * possible before any School context exists.
     */
    public function up(): void
    {
        Schema::create('school_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('type')->default('platform_subdomain'); // platform_subdomain|custom
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_domains');
    }
};
