<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4C -- one lower-bound percentage threshold belonging to
     * a GradeScale (ADR 0035). Deliberately stores ONLY
     * `min_percentage` -- no upper bound, no sequence column, no
     * PostgreSQL range type, no `EXCLUDE` constraint, no `btree_gist`
     * extension. This codebase has explicitly considered and rejected
     * that machinery twice already, for `academic_years`' and
     * `employment_records`' own overlap concerns (both migrations'
     * docblocks call it "premature complexity" for reference/low-write
     * data); this design makes an exclusion constraint unnecessary
     * here rather than merely avoiding it again.
     *
     * A future normalized percentage P maps to the GradeBand with the
     * greatest `min_percentage <= P`. A scale is COMPLETE (covers the
     * whole 0.00-100.00 domain with no gap) if and only if a band with
     * `min_percentage = 0.00` exists -- no separate gap algorithm is
     * needed, because there is no upper bound stored to leave a gap
     * against.
     *
     * Duplicate thresholds within one scale are rejected by
     * `grade_bands_min_percentage_unique` alone -- a plain UNIQUE
     * index, inherently race-safe under PostgreSQL's own guarantee, no
     * lock required for THIS specific invariant (the composite parent
     * `lockForUpdate()` used elsewhere protects the coverage/lifecycle
     * invariants instead -- see ADR 0035).
     *
     * Mutable (create/update/delete) ONLY while the parent GradeScale
     * is `draft`, enforced by `GradeScaleService` under a parent-row
     * `lockForUpdate()` -- never a database trigger (ADR 0035 records
     * why: the row-lock protocol already makes the application-level
     * protocol correct under concurrency, and a cross-row
     * parent-status-checking trigger would be a genuinely new trigger
     * shape in this codebase, not a precedented one). Once the parent
     * has ever been `active`, no band may be added, edited, or removed
     * -- a grading-policy change is always a new GradeScale.
     */
    public function up(): void
    {
        Schema::create('grade_bands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('grade_scale_id');

            $table->decimal('min_percentage', 5, 2);
            $table->string('label', 32);

            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['grade_scale_id', 'min_percentage'], 'grade_bands_min_percentage_unique');
            $table->index(['grade_scale_id']);

            $table->foreign(['grade_scale_id', 'school_id'], 'grade_bands_grade_scale_fk')
                ->references(['id', 'school_id'])->on('grade_scales')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE grade_bands ADD CONSTRAINT grade_bands_min_percentage_range_check '.
            'CHECK (min_percentage >= 0 AND min_percentage <= 100)'
        );

        TenantRls::enable('grade_bands');
    }

    public function down(): void
    {
        TenantRls::disable('grade_bands');
        Schema::dropIfExists('grade_bands');
    }
};
