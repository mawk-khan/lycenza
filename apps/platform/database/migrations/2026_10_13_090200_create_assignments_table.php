<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0I.3 (Assignments) -- the second concrete LMS fact (ADR
     * 0039). A staff-authored unit of work -- title, instructions,
     * optional resource attachments (via the Documents `assignment_id`
     * owner arm, companion migration), a due date, and a lifecycle --
     * that a SubjectOffering's roster is expected to complete. Never
     * itself a grade-bearing record (docs/modules/LMS.md §4).
     *
     * ONE PARENT ONLY, mirroring `learning_content`'s own shape exactly
     * (Phase 0I.2, ADR 0039 decision 2): an Assignment belongs to
     * exactly one SubjectOffering, which already pins AcademicYear +
     * Campus + GradeLevel + Subject -- none of those is denormalized
     * here. `unique(id, school_id)` is kept for the Documents owner-arm
     * FK below.
     *
     * NO `code` COLUMN -- same reasoning as `learning_content`: no
     * external reference/citation need a short code would serve.
     *
     * NO SEQUENCE COLUMN -- unlike `learning_content` (an ordered
     * reading list), Assignments have no demonstrated product need for
     * a manual display order; `due_on` is the natural, already-present
     * ordering key. Adding one now would be speculative (CLAUDE.md
     * rule 2).
     *
     * NO AUTHOR/OWNER COLUMN -- identical reasoning to
     * `learning_content`'s own migration: ADR 0039 decision 6 (teacher
     * authorization capability-only for v1) is explicit that no
     * teacher-to-Offering ownership record exists yet in this
     * codebase; a `created_by_employee_id`-shaped column would be a de
     * facto ownership record this checkpoint has no invariant to
     * enforce. Matches every sibling academic entity
     * (SyllabusUnit/CurriculumDelivery/Examination/ExaminationPaper/
     * LearningContent) -- none stores a creator identity.
     *
     * `due_on` is a School-LOCAL calendar DATE (mirroring
     * `Examination.starts_on`/`ends_on`'s exact "School calendar date"
     * convention, never a UTC timestamp -- the same reasoning
     * `docs/modules/ACADEMICS.md`/`CurriculumDeliveryService` document
     * extensively: a UTC timestamp is a different calendar day for
     * several hours daily in most Indian timezones). Deliberately
     * NULLABLE at the schema layer: a `draft` Assignment may still be
     * under preparation with no due date decided yet; the Application
     * service requires it to be set before `publish()` (the identical
     * "real invariant checked only at the state-changing transition"
     * shape ADR 0035's GradeScale `assertComplete()` already
     * established for `active`). No time-of-day component: "due by the
     * end of this School-local calendar day" is sufficient for v1 and
     * avoids unneeded timezone/clock-comparison machinery (rule 2) --
     * ADR 0039 §10 already commits due date to being
     * informational/display-only, not a structural acceptance
     * boundary, so a coarser grain is not a functional gap.
     *
     * LIFECYCLE: `draft` | `published` | `closed`, exactly three legal
     * transitions (draft->published, published->closed,
     * closed->published) -- ADR 0039 §10's own frozen contract
     * ("closed -> published reopening is an ordinary status
     * transition, not a one-way door"), using the identical
     * closed-transition-map shape `learning_content`
     * (draft/published/archived) and ADR 0035's GradeScale
     * (draft/active/inactive) already established. See
     * App\Domain\LMS\Application\AssignmentService.
     *
     * No Student, Guardian, Employee or teacher identity anywhere in
     * this table -- classified Confidential, not Sensitive
     * (docs/security/DATA-CLASSIFICATION.md), the same reasoning as
     * `learning_content`/`syllabus_units`/`examinations`.
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('subject_offering_id');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status')->default('draft'); // draft|published|closed -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['subject_offering_id']);
            $table->index(['school_id', 'status']);

            // Tenant-pinned parent reference. RESTRICT: a SubjectOffering
            // carrying Assignments must never be hard-deleted out from
            // under it (that Offering has no delete route today --
            // defensive completeness, matching `learning_content`'s).
            $table->foreign(['subject_offering_id', 'school_id'], 'assignments_subject_offering_fk')
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE assignments ADD CONSTRAINT assignments_status_check '.
            "CHECK (status IN ('draft', 'published', 'closed'))"
        );

        TenantRls::enable('assignments');
    }

    public function down(): void
    {
        TenantRls::disable('assignments');
        Schema::dropIfExists('assignments');
    }
};
