<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B.7A: the durable per-Student unit of a rollover plan --
     * see docs/modules/STUDENT-ENROLLMENT.md ("Academic-Year Rollover &
     * Promotion — Architecture Decision (Phase 1B.7)") for the full
     * rationale. Columns are deliberately grouped into three families
     * that will be populated at three different times by three
     * different future checkpoints -- NONE of them are populated by
     * anything in this checkpoint:
     *
     *   1. CONFIGURATION (`mapping_id`, `decision`, `target_section_id`,
     *      `roll_number_strategy`, `target_roll_number`) -- set by a
     *      future plan-population/override step (Phase 1B.7B).
     *   2. VALIDATION / STALENESS (`validation_result`,
     *      `validation_reason`, the four `*_snapshot` columns) -- set
     *      by the future dry-run engine (Phase 1B.7B).
     *   3. EXECUTION (`execution_status`, `target_enrollment_id`,
     *      `executed_at`) -- set by the future per-Student promotion
     *      execution (Phase 1B.7C). Left fully nullable rather than
     *      defaulted to e.g. `pending` -- a freshly configured item
     *      that has never been executed should not look like a queued
     *      execution attempt.
     *
     * `source_enrollment_id` anchors the item to the EXACT, already-
     * resolved authoritative source StudentEnrollment row -- never
     * re-derived loosely from `student_id` at execution time (a
     * Student may have a superseded TRANSFERRED row alongside their
     * authoritative one for the same source year; resolving which row
     * is authoritative is Phase 1B.7B's job, but once resolved, this
     * column is what execution trusts, not a fresh re-query).
     *
     * TWO independent composite foreign keys anchor
     * `source_enrollment_id`, both against `student_enrollments`: one
     * paired with `school_id` (the standard same-School guarantee every
     * tenant-owned cross-reference in this codebase already uses) and
     * one paired with `student_id` against the small supporting
     * `unique(['id', 'student_id'])` index added to `student_enrollments`
     * in the immediately preceding migration. Together these make it
     * structurally IMPOSSIBLE to construct an item whose
     * `source_enrollment_id` belongs to a different Student than its
     * own `student_id` -- no trigger required, and no reliance on a
     * future service "getting it right" the way `student_enrollments`
     * itself must rely on `StudentEnrollmentService` alone for its own
     * internal section_id/academic_year_id consistency.
     *
     * `target_enrollment_id` uses `restrictOnDelete()`, not
     * `cascadeOnDelete()`/`nullOnDelete()` -- once an item has produced
     * a real Enrollment, that provenance link must never silently
     * disappear (this checkpoint's brief section 29); `student_enrollments`
     * has no delete endpoint today regardless, so this is defensive-only,
     * matching every other reference-table FK in this codebase.
     *
     * `mapping_id` uses a plain SINGLE-column foreign key (not paired
     * with `school_id`) with `nullOnDelete()` -- it is informational
     * provenance only ("which plan-level default produced this item's
     * initial proposal"), not a hard integrity anchor, so cross-School
     * consistency for it is application-validated rather than
     * DB-structural. This is also a correctness requirement, not just
     * a style choice: pairing `mapping_id` with the NOT-NULL `school_id`
     * column in a composite FK would make `nullOnDelete()` attempt to
     * null `school_id` too (`ON DELETE SET NULL` nulls every column in
     * a composite FK), which would violate `school_id`'s own NOT NULL
     * constraint the moment a referenced mapping row was ever deleted.
     */
    public function up(): void
    {
        Schema::create('enrollment_rollover_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('plan_id');
            $table->uuid('student_id');
            $table->uuid('source_enrollment_id');
            $table->uuid('mapping_id')->nullable();

            // --- Configuration (Phase 1B.7B populates) ---
            $table->string('decision')->default('undecided'); // undecided|promote|repeat|exclude|manual_review
            $table->uuid('target_section_id')->nullable();
            $table->string('roll_number_strategy')->nullable(); // explicit|preserve_source
            $table->string('target_roll_number')->nullable();

            // --- Validation / staleness (Phase 1B.7B populates) ---
            $table->string('validation_result')->nullable(); // reserved for Phase 1B.7B's result taxonomy
            $table->string('validation_reason')->nullable();
            $table->string('source_enrollment_status_snapshot')->nullable();
            $table->timestamp('source_enrollment_updated_at_snapshot')->nullable();
            $table->string('target_section_status_snapshot')->nullable();
            $table->timestamp('target_section_updated_at_snapshot')->nullable();

            // --- Execution (Phase 1B.7C populates) ---
            $table->string('execution_status')->nullable(); // pending|succeeded|failed|skipped
            $table->uuid('target_enrollment_id')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['plan_id', 'student_id']); // at most one item per Student per plan
            $table->unique(['plan_id', 'source_enrollment_id']); // at most one item per source Enrollment per plan
            $table->index(['school_id', 'student_id']);
            $table->index(['decision']);
            $table->index(['execution_status']);
            $table->index(['mapping_id']);
            $table->index(['target_section_id']);
            $table->index(['target_enrollment_id']);

            $table->foreign(['plan_id', 'school_id'])
                ->references(['id', 'school_id'])->on('enrollment_rollover_plans')
                ->cascadeOnDelete();

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['source_enrollment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('student_enrollments')
                ->restrictOnDelete();

            $table->foreign(['source_enrollment_id', 'student_id'])
                ->references(['id', 'student_id'])->on('student_enrollments')
                ->restrictOnDelete();

            $table->foreign(['target_section_id', 'school_id'])
                ->references(['id', 'school_id'])->on('sections')
                ->restrictOnDelete();

            $table->foreign(['target_enrollment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('student_enrollments')
                ->restrictOnDelete();

            $table->foreign('mapping_id')
                ->references('id')->on('enrollment_rollover_mappings')
                ->nullOnDelete();
        });

        TenantRls::enable('enrollment_rollover_items');
    }

    public function down(): void
    {
        TenantRls::disable('enrollment_rollover_items');
        Schema::dropIfExists('enrollment_rollover_items');
    }
};
