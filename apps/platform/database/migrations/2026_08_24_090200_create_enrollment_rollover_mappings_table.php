<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B.7A: a plan's explicit, reusable source->target
     * placement configuration -- never inferred from GradeLevel
     * `sequence`, Grade names/codes, or Section names (the architecture
     * decision explicitly forbids all three). Two granularities are
     * supported in ONE normalized row shape, matching this checkpoint's
     * brief section 12:
     *
     *   - `source_section_id` NULL   -> a Grade-level DEFAULT: applies
     *     to any Student whose source Section belongs to
     *     `source_grade_level_id` and has no more specific mapping.
     *   - `source_section_id` set    -> a Section-specific OVERRIDE for
     *     exactly that source Section.
     *
     * `target_grade_level_id` is always required (a mapping ALWAYS
     * fixes at least the target Grade); `target_section_id` is
     * nullable because a Grade-level default may intentionally defer
     * the exact target Section to per-Student resolution. Repeat/
     * retention has NO separate flag or column -- it is represented
     * simply by `target_grade_level_id = source_grade_level_id`; a
     * genuine promotion is `target_grade_level_id <> source_grade_level_id`.
     * Terminal Grade is represented by the ABSENCE of any mapping row
     * for that `source_grade_level_id` at all -- never a row with a
     * null target.
     *
     * Deliberately NOT structurally enforced by this schema (documented
     * here rather than silently assumed, per this checkpoint's brief
     * section 33/34): that `source_section_id`'s own `grade_level_id`
     * equals `source_grade_level_id`, that `target_section_id`'s own
     * `grade_level_id` equals `target_grade_level_id`, or that either
     * Section belongs to the plan's declared source/target
     * AcademicYear. `sections` exposes no `unique(['id',
     * 'grade_level_id'])`/`unique(['id', 'academic_year_id'])` today,
     * and this checkpoint deliberately does NOT alter Academic
     * Structure merely to enable it (unlike the narrow, explicitly
     * anticipated `student_enrollments` addition in the previous
     * migration) -- these three invariants are APPLICATION-validated,
     * by the future dry-run/eligibility engine (Phase 1B.7B), not
     * database-structural. What IS database-structural here: every
     * reference belongs to the SAME School as the plan (composite FKs
     * below), and at most one Grade-level default and one
     * Section-specific override exist per source Grade/Section within
     * one plan (the two partial unique indexes below).
     */
    public function up(): void
    {
        Schema::create('enrollment_rollover_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('plan_id');
            $table->uuid('source_grade_level_id');
            $table->uuid('source_section_id')->nullable();
            $table->uuid('target_grade_level_id');
            $table->uuid('target_section_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables a composite FK from rollover items
            $table->index(['plan_id']);
            $table->index(['source_grade_level_id']);
            $table->index(['source_section_id']);
            $table->index(['target_grade_level_id']);
            $table->index(['target_section_id']);

            // Mapping rows have no meaning or existence outside their
            // plan (unlike an Enrollment's relationship to its Student,
            // or a Section's relationship to its AcademicYear, both of
            // which are independently significant reference data) --
            // cascadeOnDelete is therefore correct here, not merely
            // defensive.
            $table->foreign(['plan_id', 'school_id'])
                ->references(['id', 'school_id'])->on('enrollment_rollover_plans')
                ->cascadeOnDelete();

            $table->foreign(['source_grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['target_grade_level_id', 'school_id'])
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['source_section_id', 'school_id'])
                ->references(['id', 'school_id'])->on('sections')
                ->restrictOnDelete();

            $table->foreign(['target_section_id', 'school_id'])
                ->references(['id', 'school_id'])->on('sections')
                ->restrictOnDelete();
        });

        // At most one Grade-level default per (plan, source Grade).
        DB::statement(
            'CREATE UNIQUE INDEX enrollment_rollover_mappings_one_grade_default '.
            'ON enrollment_rollover_mappings (plan_id, source_grade_level_id) '.
            'WHERE source_section_id IS NULL'
        );

        // At most one Section-specific override per (plan, source Section).
        DB::statement(
            'CREATE UNIQUE INDEX enrollment_rollover_mappings_one_section_override '.
            'ON enrollment_rollover_mappings (plan_id, source_section_id) '.
            'WHERE source_section_id IS NOT NULL'
        );

        TenantRls::enable('enrollment_rollover_mappings');
    }

    public function down(): void
    {
        TenantRls::disable('enrollment_rollover_mappings');
        Schema::dropIfExists('enrollment_rollover_mappings');
    }
};
