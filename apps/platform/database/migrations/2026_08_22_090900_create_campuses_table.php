<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data. Campus belongs to exactly one School and is
     * NOT itself a tenant boundary (ADR 0004) -- RLS is still applied
     * so a Campus can never be read/written outside its own School's
     * context, matching every other tenant-owned table's isolation
     * guarantee.
     */
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']); // enables composite FKs from future campus-scoped children
            $table->index('school_id');
        });

        TenantRls::enable('campuses');
    }

    public function down(): void
    {
        TenantRls::disable('campuses');
        Schema::dropIfExists('campuses');
    }
};
