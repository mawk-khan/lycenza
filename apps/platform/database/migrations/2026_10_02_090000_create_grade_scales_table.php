<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4C -- one named, School-owned reference mapping that
     * converts a normalized percentage into a discrete grade outcome
     * (ADR 0034). School-only parent -- deliberately no AcademicYear,
     * GradeLevel, Subject, Examination or ExaminationPaper reference;
     * independent of the Examination chain, exactly as ADR 0032 already
     * anticipated ("GradeScale... has no dependency on the Examination
     * chain and could ship in parallel").
     *
     * Lifecycle is `draft|active|inactive`, with exactly three legal
     * transitions (draft->active, active->inactive, inactive->active);
     * every other transition, including every no-op, is illegal --
     * enforced entirely in
     * `App\Domain\Examinations\Application\GradeScaleService` under a
     * parent-row `lockForUpdate()`, never by a raw status
     * mass-assignment and never by a database trigger (ADR 0034).
     * `inactive` is reachable ONLY via `active`, so it structurally
     * means "this scale was previously active, and its GradeBands
     * (`grade_bands`) are permanently frozen" -- no separate
     * `ever_activated` column is needed.
     *
     * Case-insensitive code uniqueness is UNCONDITIONAL (no WHERE),
     * mirroring `examinations_year_code_ci_unique` exactly -- an
     * inactive scale continues to reserve its code.
     *
     * No DELETE route exists for this table: a School's retired
     * grading policy is marked `inactive` and kept, never removed,
     * since a future Result may hold a real reference to it. A policy
     * change is represented by creating a NEW GradeScale, never by
     * rewriting bands on one that has ever been active.
     */
    public function up(): void
    {
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            $table->string('code', 64);
            $table->string('name');

            $table->string('status')->default('draft'); // draft|active|inactive -- see CHECK below
            $table->timestamps();

            // Retained so `grade_bands` (this same migration set) and a
            // future Result can reference this table tenant-pinned,
            // exactly as `examinations`/`examination_papers` already do.
            $table->unique(['id', 'school_id']);
        });

        DB::statement(
            'ALTER TABLE grade_scales ADD CONSTRAINT grade_scales_status_check '.
            "CHECK (status IN ('draft', 'active', 'inactive'))"
        );

        // Case-insensitive code uniqueness within the School. An
        // expression index rather than a plain unique index because
        // `App\Support\NormalizesCode` uppercases at the Eloquent
        // mutator layer only -- a raw insert bypassing that mutator
        // would not collide with a plain-string index.
        DB::statement(
            'CREATE UNIQUE INDEX grade_scales_school_id_code_ci_unique '.
            'ON grade_scales (school_id, upper(code))'
        );

        TenantRls::enable('grade_scales');
    }

    public function down(): void
    {
        TenantRls::disable('grade_scales');
        Schema::dropIfExists('grade_scales');
    }
};
