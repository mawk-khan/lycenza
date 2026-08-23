<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D section 32. Named `academic_departments`, deliberately
     * NOT the generic `departments` -- a future HR module will need its
     * own organizational-department concept, and this table exists
     * solely to group Subjects academically. Keeping the name specific
     * now avoids a semantic collision/rename later.
     */
    public function up(): void
    {
        Schema::create('academic_departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
        });

        TenantRls::enable('academic_departments');
    }

    public function down(): void
    {
        TenantRls::disable('academic_departments');
        Schema::dropIfExists('academic_departments');
    }
};
