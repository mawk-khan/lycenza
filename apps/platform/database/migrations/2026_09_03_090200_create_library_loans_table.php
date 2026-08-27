<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10A -- the circulation/loan record. One row per checkout
     * event; a returned loan is never deleted or reused for a
     * subsequent checkout of the same Copy (a fresh row is created
     * instead) -- this is what makes loan history permanently valid
     * even after the referenced Copy/Title is later deactivated (rule
     * 73), and matches every other historical-record precedent in this
     * repository (StudentEnrollment, EmploymentRecord).
     *
     * `library_copy_id` -- composite FK against `library_copies(id,
     * school_id)`, restrict-on-delete (defensive-only, Copies have no
     * delete endpoint).
     *
     * `student_id` -- composite FK against `students(id, school_id)`,
     * cascade-on-delete, mirroring `student_subject_enrollments.student_id`
     * exactly (a Loan has no meaning independent of its Student).
     *
     * Deliberately NO issuing/receiving Employee reference
     * (docs/modules/LIBRARY.md "Employee issuer/receiver decision"):
     * `App\Support\Audit\AuditRecorder` already captures the acting
     * User for every checkout/check-in call, which is the actual
     * "who performed this" record this checkpoint needs -- resolving
     * that acting User to a specific Employee row would need a
     * User<->Employee link this checkpoint has no proven need for and
     * would be exactly the speculative field CLAUDE.md rule 2 forbids.
     * Addable later, additively, if a real reporting need appears.
     *
     * `status` (active|returned) PLUS full timestamps
     * (`checked_out_at`/`due_at`/`checked_in_at`) rather than either
     * alone -- `status` is what the partial unique index below and
     * ordinary "is this loan currently open" queries need; the
     * timestamps are what a due-date/overdue-detection need (out of
     * THIS checkpoint's scope, but the data it would need already
     * exists) and the historical record itself require. `due_at` is
     * REQUIRED, supplied directly by the caller at checkout time --
     * deliberately no holiday-aware calendar/grade-specific default
     * lending-period calculation (checkpoint brief section 13).
     *
     * THE core invariant (checkpoint brief section 11): one physical
     * Copy must never have two simultaneous active Loans. Enforced by
     * PostgreSQL itself via a partial unique index on
     * (library_copy_id) WHERE status = 'active' -- the exact
     * `student_enrollments_one_active_per_student_year`/
     * `academic_years_one_active_per_school` pattern already proven in
     * this repository, never an application-level check-then-insert
     * (which leaves a real race window -- see
     * tests/Feature/Library/LibraryLoanCheckoutConcurrencyTest.php for
     * the required real-two-process proof).
     *
     * A returned Loan transitioning back to "active" (i.e. a double
     * check-in, or any other impossible transition) is rejected by
     * `App\Domain\Library\Application\LibraryLoanService::checkIn()`'s
     * conditional `UPDATE ... WHERE status = 'active'` (zero affected
     * rows -> reject), the identical pattern
     * `AcademicYearService::activate()` already established -- not a
     * database CHECK constraint, since "was this specific transition
     * legal" is inherently an application-level state-machine question
     * once more than two states exist, exactly like AcademicYear's own
     * status transitions.
     */
    public function up(): void
    {
        Schema::create('library_loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('library_copy_id');
            $table->uuid('student_id');
            $table->string('status')->default('active'); // active|returned
            $table->timestamp('checked_out_at');
            $table->timestamp('due_at');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'student_id']);
            $table->index(['library_copy_id']);
            $table->index(['status']);

            $table->foreign(['library_copy_id', 'school_id'])
                ->references(['id', 'school_id'])->on('library_copies')
                ->restrictOnDelete();

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE library_loans ADD CONSTRAINT library_loans_due_after_checkout_check CHECK (due_at >= checked_out_at)');
        DB::statement('ALTER TABLE library_loans ADD CONSTRAINT library_loans_checkin_after_checkout_check CHECK (checked_in_at IS NULL OR checked_in_at >= checked_out_at)');
        DB::statement('ALTER TABLE library_loans ADD CONSTRAINT library_loans_status_checkin_consistency_check CHECK ((status = \'returned\') = (checked_in_at IS NOT NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX library_loans_one_active_per_copy '.
            "ON library_loans (library_copy_id) WHERE status = 'active'"
        );

        TenantRls::enable('library_loans');
    }

    public function down(): void
    {
        TenantRls::disable('library_loans');
        Schema::dropIfExists('library_loans');
    }
};
