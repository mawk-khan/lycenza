<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.3A (Syllabus Foundation): one ordered unit of
     * instructional content that a SubjectOffering is expected to
     * cover -- "Unit 3: Quadratic Equations, taught third". The first
     * concrete Academics fact.
     *
     * This is a CATALOGUE of EXPECTED content. It records nothing about
     * what was actually taught (a future Curriculum Delivery
     * checkpoint), nothing about a lesson (future Lesson Planning), and
     * nothing about any Student. Grading/marks/grade scales belong to
     * Examinations; assignments/submissions belong to LMS. See
     * docs/modules/ACADEMICS.md.
     *
     * ONE PARENT ONLY. A SyllabusUnit belongs to exactly one
     * SubjectOffering, which already pins AcademicYear + Campus +
     * GradeLevel + Subject. `academic_year_id`/`campus_id`/
     * `grade_level_id`/`subject_id` are deliberately NOT denormalized
     * here: the composite-context pattern
     * (`timetable_entries`/`attendance_records`) exists to pin TWO
     * parents to the SAME context, and this table has only one parent,
     * so copying context would add drift surface for no invariant
     * (CLAUDE.md rule 70 applies to cross-parent pinning, which does
     * not arise). `unique(id, school_id)` is kept so a future
     * Curriculum Delivery row can reference this table tenant-pinned.
     *
     * Both REQUIRED and ELECTIVE SubjectOfferings are supported -- the
     * syllabus belongs to the Offering itself, so Timetable v1's
     * required-only scheduling restriction (which exists because
     * scheduling presumes a Section-wide cohort) deliberately does not
     * apply to a catalogue.
     *
     * Code uniqueness mirrors `timetable_periods_school_id_code_ci_unique`/
     * `ledger_accounts_school_id_code_ci_unique`'s exact expression-index
     * pattern rather than a plain unique index -- `App\Support\NormalizesCode`
     * uppercases at the Eloquent mutator layer, but a raw SQL insert
     * bypassing that mutator would not collide with a plain-string
     * index, so a true case-insensitive expression index is used
     * instead, immune to any caller bypassing the model layer.
     *
     * That index is UNCONDITIONAL -- deliberately NOT scoped
     * `WHERE status = 'active'` like `timetable_entries`' slot indexes.
     * An inactive unit continues to reserve its code, which is exactly
     * what makes reactivation conflict-free and is why this entity
     * needs no activate/deactivate lifecycle command (see the model's
     * docblock).
     *
     * `sequence` is ordering only and is deliberately NOT unique: two
     * units may legitimately share a position mid-reorder, and a unique
     * sequence would turn every reorder into a multi-statement dance
     * through temporary values. Ties break deterministically on
     * upper(code).
     */
    public function up(): void
    {
        Schema::create('syllabus_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('subject_offering_id');
            $table->string('code', 64);
            $table->string('title');
            $table->unsignedInteger('sequence');
            $table->string('status')->default('active'); // active|inactive -- see CHECK below
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['subject_offering_id']);
            $table->index(['school_id', 'status']);

            // Tenant-pinned parent reference. RESTRICT: a SubjectOffering
            // carrying syllabus content must never be hard-deleted out
            // from under it (that Offering has no delete route today --
            // this is defensive completeness, matching every other
            // Academic Structure reference).
            $table->foreign(['subject_offering_id', 'school_id'], 'syllabus_units_subject_offering_fk')
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE syllabus_units ADD CONSTRAINT syllabus_units_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        // Case-insensitive code uniqueness WITHIN one SubjectOffering --
        // see this migration's docblock. The same normalized code may
        // legitimately exist under a different Offering.
        DB::statement(
            'CREATE UNIQUE INDEX syllabus_units_offering_code_ci_unique '.
            'ON syllabus_units (school_id, subject_offering_id, upper(code))'
        );

        TenantRls::enable('syllabus_units');
    }

    public function down(): void
    {
        TenantRls::disable('syllabus_units');
        Schema::dropIfExists('syllabus_units');
    }
};
