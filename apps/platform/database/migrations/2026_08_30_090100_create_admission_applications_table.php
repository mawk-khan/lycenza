<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1D.1 -- one specific application: one Applicant, applying
     * to one School, for one academic context (AcademicYear + Campus +
     * GradeLevel), with its own status/decision/conversion state
     * (docs/modules/ADMISSIONS.md §3/§5). No `section_id` -- Section
     * is a conversion-time-only decision, never pre-committed on the
     * application (`ADMISSIONS.md` §5).
     *
     * `status`'s ALLOWED VALUES are deliberately NOT a database CHECK
     * constraint (`ADMISSIONS.md` §6A) -- this matches the exact,
     * confirmed precedent every comparable lifecycle-status column in
     * this codebase already uses (`students`, `student_enrollments`,
     * `student_subject_enrollments`: all a plain string column with a
     * descriptive comment, application-level guard only). What IS a
     * database CHECK is the cross-column consistency invariant below
     * (`admission_applications_conversion_provenance_check`) -- the
     * same category of enforcement as
     * `student_subject_enrollments_date_range_check`.
     *
     * Two invariants are PostgreSQL-enforced, not application check-
     * then-insert:
     * - `admission_applications_conversion_provenance_check`: `status =
     *   'converted'` if and only if all three conversion-provenance
     *   columns are non-null; every other status value (including any
     *   future one) must carry NULL provenance. Written as a two-armed
     *   OR so an arbitrary non-'converted' string can never carry
     *   provenance (`ADMISSIONS.md` §5/§6).
     * - `admission_applications_one_open_per_context`: a partial unique
     *   index on `(applicant_id, academic_year_id, campus_id,
     *   grade_level_id)` WHERE `status IN ('draft', 'submitted',
     *   'accepted')` -- at most one simultaneously OPEN application per
     *   Applicant per exact academic context. Reapplication after
     *   `rejected`/`withdrawn`/`converted` is always allowed (those
     *   statuses fall outside the partial index), and a different
     *   AcademicYear/Campus/GradeLevel is always a different key
     *   (`ADMISSIONS.md` §5/§7).
     *
     * `converted_student_id`/`converted_student_enrollment_id` are
     * deliberately NOT unique -- a future re-admission checkpoint may
     * legitimately need multiple historical `AdmissionApplication` rows
     * pointing to the same `Student` (`ADMISSIONS.md` §10), and nothing
     * in the accepted architecture establishes that one
     * `StudentEnrollment` must be attributed to at most one
     * `AdmissionApplication` (in the v1 conversion flow this could
     * never happen anyway, since `StudentEnrollmentService::enroll()`
     * always creates a brand-new row per conversion) -- adding a
     * uniqueness constraint neither doc requires would be speculative
     * schema (root CLAUDE.md rule 2).
     *
     * Delete behavior: every outbound FK from this table restricts on
     * delete -- `applicants`, `academic_years`, `campuses`,
     * `grade_levels`, `students`, and `student_enrollments` are all
     * durable records this table must never lose its historical
     * reference to, even in the defensive case one of them is somehow
     * removed (none of those tables expose a delete endpoint today,
     * CLAUDE.md rule 73). This is a deliberately more conservative
     * choice than `student_enrollments`' own cascade-on-Student-delete
     * policy -- an Admissions decision record is historically important
     * enough that it must survive even a defensive Applicant removal,
     * never silently disappear with it.
     */
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('applicant_id');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->string('status')->default('draft'); // draft|submitted|accepted|rejected|withdrawn|converted
            $table->text('decision_note')->nullable();
            $table->uuid('converted_student_id')->nullable();
            $table->uuid('converted_student_enrollment_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'applicant_id']);
            $table->index(['academic_year_id']);
            $table->index(['campus_id']);
            $table->index(['grade_level_id']);

            $table->foreign(['applicant_id', 'school_id'], 'aa_applicant_school_foreign')
                ->references(['id', 'school_id'])->on('applicants')
                ->restrictOnDelete();

            $table->foreign(['academic_year_id', 'school_id'], 'aa_academic_year_school_foreign')
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'], 'aa_campus_school_foreign')
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'], 'aa_grade_level_school_foreign')
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['converted_student_id', 'school_id'], 'aa_converted_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();

            $table->foreign(['converted_student_enrollment_id', 'school_id'], 'aa_converted_enrollment_school_foreign')
                ->references(['id', 'school_id'])->on('student_enrollments')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE admission_applications ADD CONSTRAINT admission_applications_conversion_provenance_check '.
            "CHECK ((status = 'converted' AND converted_student_id IS NOT NULL AND converted_student_enrollment_id IS NOT NULL AND converted_at IS NOT NULL) ".
            "OR (status <> 'converted' AND converted_student_id IS NULL AND converted_student_enrollment_id IS NULL AND converted_at IS NULL))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX admission_applications_one_open_per_context '.
            'ON admission_applications (applicant_id, academic_year_id, campus_id, grade_level_id) '.
            "WHERE status IN ('draft', 'submitted', 'accepted')"
        );

        TenantRls::enable('admission_applications');
    }

    public function down(): void
    {
        TenantRls::disable('admission_applications');
        Schema::dropIfExists('admission_applications');
    }
};
