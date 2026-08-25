<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1C.1 -- the authoritative Student<->SubjectOffering
     * membership discovered missing during Phase 5C.1's dependency
     * audit (docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md
     * "Dependency discovered by Phase 5C.1"). `subject_offerings`
     * (Phase 0D) represents a Grade-level offering DEFINITION (Subject x
     * GradeLevel x Campus x AcademicYear) -- it never had, and still
     * does not have, a Student roster of its own.
     *
     * This table exists ONLY for offerings a Student must explicitly
     * choose (`subject_offerings.is_required = false` -- electives,
     * optional subjects, subject groups). A REQUIRED offering's roster
     * is never materialized here: every Student whose current active
     * StudentEnrollment matches that offering's AcademicYear+GradeLevel
     * is implicitly enrolled (App\Domain\Students\Application\SubjectOfferingRosterReadService
     * derives this at read time). Creating one row per Student per
     * mandatory Subject would be a large, purely-derivable duplication
     * of student_enrollments -- CLAUDE.md rule 2 ("no speculative
     * infrastructure for a need that doesn't exist"), and the checkpoint
     * brief's own explicit instruction not to do this.
     *
     * `academic_year_id` is denormalized off `subject_offering_id`
     * (which already implies exactly one AcademicYear) for the SAME two
     * reasons student_enrollments denormalizes academic_year_id off
     * section_id (see that migration's docblock): (a) a partial unique
     * index cannot reference a joined table's column, and (b) every
     * parent reference gets its own composite-FK protection rather than
     * relying on RLS alone at INSERT time. Consistency between
     * `subject_offering_id` and `academic_year_id` is guaranteed by
     * construction -- App\Domain\Students\Application\StudentSubjectEnrollmentService
     * is the only sanctioned write path and always derives
     * `academic_year_id` FROM the caller-chosen SubjectOffering
     * server-side, exactly like StudentEnrollmentService derives
     * academic_year_id/campus_id/grade_level_id from a chosen Section.
     *
     * Academic COMPATIBILITY (the chosen SubjectOffering's
     * AcademicYear/GradeLevel/Campus matching the Student's own CURRENT
     * active StudentEnrollment) is deliberately NOT a database
     * constraint here -- it is enforced by
     * StudentSubjectEnrollmentService at write time, and independently
     * RE-DERIVED at read time by SubjectOfferingRosterReadService on
     * every roster query (a join against the Student's live, current
     * StudentEnrollment, never a cached/denormalized flag on this
     * table). This means a Student who is promoted/transferred out of
     * the compatible Grade/Section AFTER this row was created
     * automatically stops appearing in the offering's CURRENT roster
     * without StudentEnrollmentService needing any knowledge of subject
     * enrollment, and without a database trigger -- the identical
     * "freshness by construction, zero cross-service coupling" pattern
     * Phase 5B.3's GradeAudienceResolver/SectionAudienceResolver
     * already established for Communications, applied here one layer
     * lower in the Academic domain itself. See the documentation file's
     * "Lifecycle when Student moves Grade/Section" section for the full
     * contract.
     *
     * Two invariants are PostgreSQL-enforced, not application
     * check-then-insert, following the
     * `student_enrollments_one_active_per_student_year` precedent
     * exactly:
     * - A partial unique index on (student_id, subject_offering_id)
     *   WHERE status = 'active' -- a Student cannot have two active
     *   memberships in the identical SubjectOffering at once. Note this
     *   does NOT enforce elective-group mutual exclusivity (e.g. "French
     *   OR Spanish, never both") -- no elective-group/exclusivity
     *   concept exists anywhere in Subject/SubjectOffering today, and
     *   inventing one is explicitly out of scope for this foundational
     *   checkpoint (CLAUDE.md rule 2); see the documentation file's
     *   "Deferred work" section.
     * - A CHECK constraint that `ends_on >= starts_on` when `ends_on`
     *   is present, identical to student_enrollments'.
     *
     * Delete behavior mirrors student_enrollments exactly: the
     * composite FK to `students` cascades; the composite FKs to
     * `subject_offerings`/`academic_years` restrict-on-delete (neither
     * exposes a delete endpoint -- CLAUDE.md rule 73 -- so this is
     * defensive-only).
     */
    public function up(): void
    {
        Schema::create('student_subject_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('subject_offering_id');
            $table->uuid('academic_year_id');
            $table->string('status')->default('active'); // active|withdrawn|cancelled|transferred
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables future composite FKs from this row's own children
            $table->index(['school_id', 'student_id']);
            $table->index(['subject_offering_id']);
            $table->index(['academic_year_id']);
            $table->index(['status']);

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['subject_offering_id', 'school_id'])
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE student_subject_enrollments ADD CONSTRAINT student_subject_enrollments_date_range_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement(
            'CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_offering '.
            "ON student_subject_enrollments (student_id, subject_offering_id) WHERE status = 'active'"
        );

        TenantRls::enable('student_subject_enrollments');
    }

    public function down(): void
    {
        TenantRls::disable('student_subject_enrollments');
        Schema::dropIfExists('student_subject_enrollments');
    }
};
