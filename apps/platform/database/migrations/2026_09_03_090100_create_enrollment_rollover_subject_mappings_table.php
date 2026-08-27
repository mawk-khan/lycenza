<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1G.1 (docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md
     * section 5, "Option B, offering-level only"): a plan's explicit,
     * durable source SubjectOffering -> target SubjectOffering elective
     * carry-forward configuration -- one row per (plan, source
     * offering), owned by (and cascade-deleted with) the SAME
     * EnrollmentRolloverPlan that `enrollment_rollover_mappings`
     * (Grade/Section placement) already belongs to. This is deliberately
     * NOT a second top-level rollover aggregate, and it is deliberately
     * NOT keyed by ElectiveGroup -- the target Offering's own CURRENT
     * `elective_group_id` is authoritative at dry-run/execution time
     * (Phase 1F.2's `StudentSubjectEnrollmentService::enroll()` already
     * derives it fresh), so this table only ever needs to know which
     * target Offering a source Offering maps to.
     *
     * Three-state semantics live in row PRESENCE, exactly mirroring
     * `enrollment_rollover_mappings`' own "absence = terminal Grade"
     * convention one layer down -- never a boolean flag column:
     *
     *   - no row for a source Offering  -> UNCONFIGURED (blocking at a
     *     future dry-run stage, Phase 1G.2 -- not this checkpoint).
     *   - row present, target NULL      -> explicit OMIT (operator
     *     deliberately chose not to carry this elective forward).
     *   - row present, target set       -> explicit carry-forward into
     *     that exact target Offering.
     *
     * Year/School validation for `source_subject_offering_id` (must
     * belong to the plan's `source_academic_year_id`) and
     * `target_subject_offering_id` (must belong to the plan's
     * `target_academic_year_id`, when non-null) is deliberately NOT
     * expressed as a database constraint here -- doing so would require
     * denormalizing the plan's source/target AcademicYear id onto this
     * table purely to build a three-column composite FK against
     * `subject_offerings(id, school_id, academic_year_id)`, which is
     * exactly the kind of speculative denormalization this checkpoint's
     * brief explicitly rejects. What IS database-structural: every
     * reference belongs to the SAME School as the plan (composite FKs
     * below, against `subject_offerings`' EXISTING `unique(['id',
     * 'school_id'])` -- no new SubjectOffering-table migration is
     * needed for this). Source/target-year membership is validated in
     * `EnrollmentRolloverPlanService::upsertSubjectMapping()` instead,
     * the same division of responsibility `enrollment_rollover_mappings`'
     * own migration already documents for its analogous Section/Grade
     * consistency gap.
     *
     * Campus/GradeLevel/ElectiveGroup consistency between source and
     * target is intentionally never checked here (or in the mutation
     * service) -- a rollover plan may legitimately promote a Grade or
     * change Campus/electives across the rollover, and per-Student
     * target compatibility is validated later, against the Student's
     * actual resolved target StudentEnrollment, in Phase 1G.2's dry-run.
     */
    public function up(): void
    {
        Schema::create('enrollment_rollover_subject_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('plan_id');
            $table->uuid('source_subject_offering_id');
            $table->uuid('target_subject_offering_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            // At most one mapping per (plan, source Offering) -- the
            // structural guarantee behind the three-state semantics
            // above (an UNCONFIGURED source Offering can never
            // accidentally collect two contradictory rows).
            $table->unique(['plan_id', 'source_subject_offering_id']);
            $table->index(['source_subject_offering_id']);
            $table->index(['target_subject_offering_id']);

            // A mapping row has no meaning or existence outside its
            // plan -- identical rationale to
            // enrollment_rollover_mappings.plan_id's cascadeOnDelete.
            $table->foreign(['plan_id', 'school_id'])
                ->references(['id', 'school_id'])->on('enrollment_rollover_plans')
                ->cascadeOnDelete();

            // SubjectOffering rows are permanent historical reference
            // data (Phase 0D section 73) -- a mapping referencing one
            // must never be able to destroy it.
            $table->foreign(['source_subject_offering_id', 'school_id'])
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();

            $table->foreign(['target_subject_offering_id', 'school_id'])
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();
        });

        TenantRls::enable('enrollment_rollover_subject_mappings');
    }

    public function down(): void
    {
        TenantRls::disable('enrollment_rollover_subject_mappings');
        Schema::dropIfExists('enrollment_rollover_subject_mappings');
    }
};
