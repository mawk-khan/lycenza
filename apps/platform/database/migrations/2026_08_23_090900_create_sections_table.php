<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 25-28, 36: a Section is AcademicYear + Campus +
     * GradeLevel scoped, and belongs to exactly ONE AcademicYear
     * (section 28's historical-safety rule) -- "Grade 5 A" in 2026-27
     * and "Grade 5 A" in 2027-28 are distinct rows, never the same row
     * reused/mutated across years. All three parent references are
     * composite-FK-protected (section 36): a School A Section can never
     * reference a School B AcademicYear/Campus/GradeLevel.
     *
     * `capacity` is advisory-only (section 27) -- no admission-blocking
     * logic reads it in this checkpoint.
     */
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->string('name');
            $table->string('code');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();
        });

        TenantRls::enable('sections');
    }

    public function down(): void
    {
        TenantRls::disable('sections');
        Schema::dropIfExists('sections');
    }
};
