<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 22-24, 62: School-wide reference data (not
     * Campus- or AcademicYear-scoped -- a multi-campus School's Grade
     * taxonomy is shared, per docs/modules/ACADEMIC-STRUCTURE.md's
     * multi-campus default). `sequence` is an explicit, independently
     * unique ordering column (section 23) -- progression is never
     * inferred from `name`/`code` parsing (Nursery/LKG/UKG/Grade 1
     * cannot be sorted correctly by string comparison).
     * `education_stage` is a free nullable string, not an enum
     * constraint -- section 24 explicitly avoids a rigid classification
     * that varies by Board/framework.
     */
    public function up(): void
    {
        Schema::create('grade_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->unsignedInteger('sequence');
            $table->string('education_stage')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'sequence']);
            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
        });

        TenantRls::enable('grade_levels');
    }

    public function down(): void
    {
        TenantRls::disable('grade_levels');
        Schema::dropIfExists('grade_levels');
    }
};
