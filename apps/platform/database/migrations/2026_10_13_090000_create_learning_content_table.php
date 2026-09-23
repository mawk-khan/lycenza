<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0I.2 (Learning Content Foundation) -- the first concrete
     * LMS fact (ADR 0039, docs/modules/LMS.md). A School-authored
     * instructional resource (a reading, a link, a note, an attached
     * file) belonging to one SubjectOffering -- the LMS counterpart to
     * `syllabus_units`' catalogue of expected content, but for actual
     * distributable material rather than a topic outline.
     *
     * ONE PARENT ONLY, mirroring `syllabus_units`' own shape exactly
     * (docs/modules/ACADEMICS.md, ADR 0039 decision 2): a LearningContent
     * belongs to exactly one SubjectOffering, which already pins
     * AcademicYear + Campus + GradeLevel + Subject -- none of those is
     * denormalized here. `unique(id, school_id)` is kept so a future
     * Documents owner-arm FK (this same checkpoint, see the companion
     * migration) and a future Assignment/SyllabusUnit/CurriculumDelivery
     * reference can pin this table tenant-safely.
     *
     * NO `code` COLUMN. Unlike SyllabusUnit, a Learning Content resource
     * has no external reference/citation need a short code would serve
     * -- `title` is its only identifying label, and titles are
     * deliberately NOT unique (two resources may legitimately share a
     * title, e.g. two different "Introduction" readings for two
     * different chapters).
     *
     * NO AUTHOR/OWNER COLUMN. ADR 0039 decision 6 (teacher authorization
     * capability-only for v1) is explicit that no teacher-to-Offering
     * ownership record exists yet in this codebase -- adding a
     * `created_by_employee_id`-shaped column here would be a de facto
     * ownership record this checkpoint does not have a real invariant
     * to enforce, mirroring the identical decision already made for
     * SyllabusUnit/CurriculumDelivery/Examination/ExaminationPaper (none
     * of which stores a creator identity).
     *
     * LIFECYCLE: `draft` | `published` | `archived`, exactly three
     * legal transitions (draft->published, published->archived,
     * archived->published) -- the same closed-map shape ADR 0035
     * (GradeScale) already established, adapted from GradeScale's
     * draft/active/inactive naming to Learning Content's own vocabulary.
     * See App\Domain\LMS\Application\LearningContentService.
     *
     * `sequence` mirrors `syllabus_units.sequence` exactly: ordering
     * only, deliberately NOT unique (two resources may share a display
     * position mid-reorder), ties broken deterministically on
     * `created_at` then `id` (no `code` exists here to break ties on).
     *
     * No Student, Guardian, Employee or teacher identity anywhere in
     * this table -- classified Confidential, not Sensitive
     * (docs/security/DATA-CLASSIFICATION.md), the same reasoning as
     * `syllabus_units`/`examinations`/`examination_papers`.
     */
    public function up(): void
    {
        Schema::create('learning_content', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('subject_offering_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('status')->default('draft'); // draft|published|archived -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['subject_offering_id']);
            $table->index(['school_id', 'status']);

            // Tenant-pinned parent reference. RESTRICT: a SubjectOffering
            // carrying Learning Content must never be hard-deleted out
            // from under it (that Offering has no delete route today --
            // defensive completeness, matching `syllabus_units`').
            $table->foreign(['subject_offering_id', 'school_id'], 'learning_content_subject_offering_fk')
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE learning_content ADD CONSTRAINT learning_content_status_check '.
            "CHECK (status IN ('draft', 'published', 'archived'))"
        );

        TenantRls::enable('learning_content');
    }

    public function down(): void
    {
        TenantRls::disable('learning_content');
        Schema::dropIfExists('learning_content');
    }
};
