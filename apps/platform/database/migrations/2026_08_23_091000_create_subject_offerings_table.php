<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 37-40, 62: the academic-offering layer so a
     * Subject is never assumed to apply to every Grade automatically.
     * AcademicYear-specific by construction (section 38) -- "2026-27
     * Grade 8 -> French" and "2027-28 Grade 8 -> Spanish" are distinct
     * rows; changing next year's offering never rewrites this year's
     * history. Deliberately NOT attached to Section (section 40) -- a
     * Subject is offered to a GradeLevel within a Campus/AcademicYear
     * and inherited by that Grade's Sections; a future section-specific
     * elective model can extend this without a redesign.
     *
     * All four parent references (AcademicYear, Campus, GradeLevel,
     * Subject) are composite-FK-protected (section 36/82).
     */
    public function up(): void
    {
        Schema::create('subject_offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('subject_id');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sequence')->nullable();
            $table->unsignedInteger('weekly_periods_target')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(
                ['school_id', 'academic_year_id', 'campus_id', 'grade_level_id', 'subject_id'],
                'subject_offerings_unique_offering',
            );
            $table->unique(['id', 'school_id']);
            $table->index(['academic_year_id']);
            $table->index(['grade_level_id']);
            $table->index(['subject_id']);

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['subject_id', 'school_id'])
                ->references(['id', 'school_id'])->on('subjects')
                ->restrictOnDelete();
        });

        TenantRls::enable('subject_offerings');
    }

    public function down(): void
    {
        TenantRls::disable('subject_offerings');
        Schema::dropIfExists('subject_offerings');
    }
};
