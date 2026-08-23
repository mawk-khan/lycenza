<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A.2: the relationship between a Student and a Guardian is a
     * first-class domain record, never father_id/mother_id columns on
     * `students` -- a Guardian may be linked to several Students
     * (siblings reuse the same Guardian row), and a Student may have
     * several Guardians.
     *
     * Same-School integrity is PostgreSQL-enforced, not application-
     * validation-only: both `student_id` and `guardian_id` are
     * composite-FK-protected against `students(id, school_id)` and
     * `guardians(id, school_id)` respectively (the same pattern
     * Section/SubjectOffering already established in Phase 0D) -- a
     * School A Student can never be paired with a School B Guardian at
     * the database level.
     *
     * `relationship_type` (family relationship) and `is_legal_guardian`
     * (legal authority) are deliberately independent columns -- a
     * grandparent can be the legal guardian without collapsing that
     * distinction into the relationship_type value itself.
     * `is_emergency_contact`/`is_authorized_pickup` are likewise
     * per-relationship, not per-Guardian, because the same Guardian can
     * have different authority for different Students.
     *
     * Two invariants are database-enforced, not application-check-then-
     * insert:
     * - `unique(school_id, student_id, guardian_id)` -- one canonical
     *   relationship row per Student/Guardian pair; differing authority
     *   flags never justify a second row for the same pair.
     * - A partial unique index on `(school_id, student_id)` WHERE
     *   `is_primary = true` -- at most one primary Guardian per Student,
     *   the same "PostgreSQL partial unique index as the concurrency-
     *   safety mechanism" pattern `academic_years_one_active_per_school`
     *   already established.
     *
     * Delete behavior: both composite foreign keys cascade FROM
     * students/guardians INTO this table (hard-deleting a Student or
     * Guardian removes only ITS OWN relationship rows), never the
     * reverse -- deleting a relationship row can never delete a Student
     * or Guardian (structurally impossible; foreign keys only cascade
     * parent-to-child), and removing one Student's relationship with a
     * shared Guardian never touches that Guardian's other relationships.
     * Students/Guardians have no delete endpoint today (they are
     * deactivated via `status`, never deleted, matching CLAUDE.md rule
     * 73's convention) -- this is defensive completeness for the rare
     * administrative hard-delete, not an expected normal operation.
     */
    public function up(): void
    {
        Schema::create('student_guardian_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('guardian_id');
            $table->string('relationship_type');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_legal_guardian')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('is_authorized_pickup')->default(false);
            $table->timestamps();

            // Duplicate-relationship prevention; also serves as the
            // "all Guardians for a Student" lookup index (school_id,
            // student_id prefix).
            $table->unique(['school_id', 'student_id', 'guardian_id']);

            // "All Students for a Guardian" lookup index -- guardian_id
            // is not a usable prefix of the unique index above (it's
            // third), so a dedicated index is needed; also backs the
            // composite FK's cascade-delete lookup when a Guardian row
            // is removed.
            $table->index(['school_id', 'guardian_id']);

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['guardian_id', 'school_id'])
                ->references(['id', 'school_id'])->on('guardians')
                ->cascadeOnDelete();
        });

        // At most one primary Guardian per Student -- the database-
        // enforced concurrency guarantee, not an application
        // check-then-update. Also serves as the "primary Guardian for a
        // Student" lookup index.
        DB::statement(
            'CREATE UNIQUE INDEX student_guardian_relationships_one_primary_per_student '.
            'ON student_guardian_relationships (school_id, student_id) WHERE is_primary = true'
        );

        TenantRls::enable('student_guardian_relationships');
    }

    public function down(): void
    {
        TenantRls::disable('student_guardian_relationships');
        Schema::dropIfExists('student_guardian_relationships');
    }
};
