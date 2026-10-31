<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS 11.5 (owner decisions 2026-10-31; ADR 0012 amendment): Student and
 * Applicant document COMPLIANCE over the one Documents repository -- no
 * second file store.
 *
 * - `documents.applicant_id`: a sixth owner of the exclusive arc, with a
 *   same-School composite key. A pre-admission document is a Document owned
 *   by the Applicant; when the application converts, the SAME row is
 *   re-pointed to the Student (no copy of the bytes). It goes with the
 *   Applicant under the existing Admissions retention (DocumentParentRetention).
 * - `student_documents`: verification (pending / verified / rejected, with
 *   who, when and a closed rejection reason), the new `birth_certificate`
 *   category (proof of age at admission; a civil record, never a statutory
 *   ID number), and the applicant record it came from. NULL verification =
 *   uploaded before verification existed ("not reviewed"), never invented.
 * - `applicant_documents`: the Applicant-side record, the same shape and
 *   guards as `student_documents`; final once archived or moved to the
 *   Student.
 * - `student_document_requirements`: a School's closed rules -- which
 *   category is Required or Optional for Students or Applicants of one
 *   academic year, optionally one Campus and/or Grade, from a date. A rule's
 *   terms are immutable: a change is retire + a new rule, so what applied
 *   when evidence was collected stays answerable. Never deleted at runtime.
 */
return new class extends Migration
{
    private const STUDENT_CATEGORIES = "'enrollment_support', 'prior_school_record', 'consent_form', 'correspondence', 'issued_certificate', 'birth_certificate'";

    private const APPLICANT_CATEGORIES = "'birth_certificate', 'prior_school_record', 'enrollment_support', 'consent_form'";

    private const REQUIREMENT_CATEGORIES = "'birth_certificate', 'prior_school_record', 'enrollment_support', 'consent_form'";

    private const REJECTION_REASONS = "'illegible', 'wrong_document', 'incomplete', 'not_valid', 'wrong_person'";

    public function up(): void
    {
        // --- Documents: the Applicant owner --------------------------------
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('applicant_id')->nullable();
            $table->index('applicant_id');
            $table->foreign(['applicant_id', 'school_id'], 'documents_applicant_fk')->references(['id', 'school_id'])->on('applicants')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_exactly_one_owner_check');
        DB::statement($this->ownerCheck(true));
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_applicant_school_unique UNIQUE (id, applicant_id, school_id)');

        // --- Student documents: verification and provenance -----------------
        Schema::table('student_documents', function (Blueprint $table) {
            $table->string('verification_status', 20)->nullable();
            $table->foreignUuid('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('rejection_reason', 30)->nullable();
            $table->uuid('source_applicant_document_id')->nullable();
            $table->unique('source_applicant_document_id');
        });
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_category_check');
        DB::statement('ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_check CHECK (category IN ('.self::STUDENT_CATEGORIES.'))');
        DB::statement($this->verificationChecks('student_documents'));

        // --- Applicant documents ----------------------------------------------
        // An applicant document is collected through ONE application and is
        // bound to it (Campus scope is the application's): the key pins the
        // application to the same Applicant and School.
        Schema::table('admission_applications', function (Blueprint $table) {
            $table->unique(['id', 'applicant_id', 'school_id'], 'admission_applications_id_applicant_school_unique');
        });
        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('applicant_id');
            $table->uuid('admission_application_id');
            $table->uuid('document_id');
            $table->string('category', 40);
            $table->string('title', 120);
            $table->date('document_date')->nullable();
            $table->uuid('form_key');
            $table->char('upload_fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignUuid('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('rejection_reason', 30)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('archive_reason', 40)->nullable();
            $table->timestampTz('moved_to_student_at')->nullable();
            $table->uuid('moved_to_student_id')->nullable();
            $table->timestampsTz();

            $table->unique('document_id');
            $table->unique(['school_id', 'form_key']);
            $table->index(['school_id', 'applicant_id']);
            $table->index(['school_id', 'admission_application_id']);
            $table->foreign(['admission_application_id', 'applicant_id', 'school_id'], 'applicant_documents_application_fk')
                ->references(['id', 'applicant_id', 'school_id'])->on('admission_applications')->cascadeOnDelete();
            $table->foreign(['applicant_id', 'school_id'])->references(['id', 'school_id'])->on('applicants')->cascadeOnDelete();
        });
        // Rule 97: a composite same-School key that may null its relationship
        // nulls only its own column, never school_id.
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_moved_student_fk FOREIGN KEY (moved_to_student_id, school_id) REFERENCES students (id, school_id) ON DELETE SET NULL (moved_to_student_id)');
        // The Document while it is the Applicant's; once moved to the Student
        // the Document's owner changes and this record is final history.
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_category_check CHECK (category IN ('.self::APPLICANT_CATEGORIES.'))');
        DB::statement("ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_archive_reason_check CHECK (archive_reason IS NULL OR archive_reason IN ('uploaded_in_error', 'superseded', 'no_longer_required'))");
        DB::statement("ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_title_check CHECK (title = btrim(title) AND char_length(title) BETWEEN 1 AND 120 AND title !~ '[[:cntrl:]]')");
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_archive_shape_check CHECK (
            (archived_at IS NULL AND archived_by_user_id IS NULL AND archive_reason IS NULL)
            OR (archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL AND archive_reason IS NOT NULL))');
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_moved_shape_check CHECK ((moved_to_student_at IS NULL) = (moved_to_student_id IS NULL) OR (moved_to_student_at IS NOT NULL AND moved_to_student_id IS NULL))');
        DB::statement("ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_verification_present_check CHECK (verification_status IN ('pending', 'verified', 'rejected'))");
        DB::statement($this->verificationChecks('applicant_documents'));
        // `student_documents.source_applicant_document_id` is provenance only
        // (no key): the Applicant record may be purged with its applicant under
        // Admissions retention, and an archived Student document is final.

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION applicant_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.applicant_id IS DISTINCT FROM OLD.applicant_id
                   OR NEW.admission_application_id IS DISTINCT FROM OLD.admission_application_id
                   OR NEW.document_id IS DISTINCT FROM OLD.document_id OR NEW.form_key IS DISTINCT FROM OLD.form_key OR NEW.upload_fingerprint IS DISTINCT FROM OLD.upload_fingerprint
                   OR NEW.uploaded_by_user_id IS DISTINCT FROM OLD.uploaded_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'applicant_documents identity is immutable' USING ERRCODE = 'check_violation';
                END IF;
                -- A retention-driven student removal may only clear the moved-to reference.
                IF OLD.moved_to_student_at IS NOT NULL AND NEW.moved_to_student_id IS NULL AND OLD.moved_to_student_id IS NOT NULL
                   AND NEW.moved_to_student_at IS NOT DISTINCT FROM OLD.moved_to_student_at AND NEW.version = OLD.version THEN
                    RETURN NEW;
                END IF;
                IF OLD.archived_at IS NOT NULL OR OLD.moved_to_student_at IS NOT NULL THEN
                    RAISE EXCEPTION 'an archived or moved Applicant document is final' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'an Applicant document change advances its version by one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_applicant_documents_guard BEFORE UPDATE ON applicant_documents
                FOR EACH ROW EXECUTE FUNCTION applicant_documents_guard();
            SQL);
        TenantRls::enable('applicant_documents');
        DB::statement('REVOKE DELETE ON applicant_documents FROM school_os_app');
        // The retention identity reads which Documents an application's records
        // name before the application (and, by cascade, its records) is deleted.
        DB::statement('GRANT SELECT ON applicant_documents TO school_os_retention');

        // --- Requirement rules --------------------------------------------------
        Schema::create('student_document_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('subject', 20);
            $table->string('category', 40);
            $table->boolean('required');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id')->nullable();
            $table->uuid('grade_level_id')->nullable();
            $table->date('effective_from');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignUuid('retired_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['school_id', 'academic_year_id']);
            $table->foreign(['academic_year_id', 'school_id'])->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['campus_id', 'school_id'], 'student_document_requirements_campus_fk')->references(['id', 'school_id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['grade_level_id', 'school_id'], 'student_document_requirements_grade_fk')->references(['id', 'school_id'])->on('grade_levels')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE student_document_requirements ADD CONSTRAINT student_document_requirements_subject_check CHECK (subject IN ('student', 'applicant'))");
        DB::statement('ALTER TABLE student_document_requirements ADD CONSTRAINT student_document_requirements_category_check CHECK (category IN ('.self::REQUIREMENT_CATEGORIES.'))');
        DB::statement('ALTER TABLE student_document_requirements ADD CONSTRAINT student_document_requirements_retired_shape_check CHECK ((retired_at IS NULL) = (retired_by_user_id IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_document_requirements_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.subject IS DISTINCT FROM OLD.subject OR NEW.category IS DISTINCT FROM OLD.category
                   OR NEW.required IS DISTINCT FROM OLD.required OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                   OR NEW.campus_id IS DISTINCT FROM OLD.campus_id OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                   OR NEW.effective_from IS DISTINCT FROM OLD.effective_from OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a document requirement''s terms are immutable (retire it and add a new one)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.retired_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a retired document requirement is final' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_document_requirements_guard BEFORE UPDATE ON student_document_requirements
                FOR EACH ROW EXECUTE FUNCTION student_document_requirements_guard();
            SQL);
        TenantRls::enable('student_document_requirements');
        DB::statement('REVOKE DELETE ON student_document_requirements FROM school_os_app');
    }

    public function down(): void
    {
        if (DB::table('applicant_documents')->exists() || DB::table('documents')->whereNotNull('applicant_id')->exists()
            || DB::table('student_documents')->where('category', 'birth_certificate')->exists()) {
            throw new RuntimeException('Refusing to roll back: Applicant or birth-certificate documents exist (SIS 11.5 evidence).');
        }

        TenantRls::disable('student_document_requirements');
        Schema::dropIfExists('student_document_requirements');
        DB::unprepared('DROP FUNCTION IF EXISTS student_document_requirements_guard()');

        DB::statement('REVOKE SELECT ON applicant_documents FROM school_os_retention');
        TenantRls::disable('applicant_documents');
        Schema::dropIfExists('applicant_documents');
        DB::unprepared('DROP FUNCTION IF EXISTS applicant_documents_guard()');
        Schema::table('admission_applications', function (Blueprint $table) {
            $table->dropUnique('admission_applications_id_applicant_school_unique');
        });

        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT IF EXISTS student_documents_verification_shape_check');
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT IF EXISTS student_documents_verification_status_check');
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT IF EXISTS student_documents_rejection_reason_check');
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_category_check');
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_check CHECK (category IN ('enrollment_support', 'prior_school_record', 'consent_form', 'correspondence', 'issued_certificate'))");
        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropUnique(['source_applicant_document_id']);
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['verification_status', 'verified_at', 'rejection_reason', 'source_applicant_document_id']);
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_id_applicant_school_unique');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_exactly_one_owner_check');
        DB::statement($this->ownerCheck(false));
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('documents_applicant_fk');
            $table->dropIndex(['applicant_id']);
            $table->dropColumn('applicant_id');
        });
    }

    private function ownerCheck(bool $withApplicant): string
    {
        $terms = ['employee_id', 'student_id', 'guardian_id', 'learning_content_id', 'assignment_id'];
        if ($withApplicant) {
            $terms[] = 'applicant_id';
        }

        return 'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_owner_check CHECK ('
            .implode(' + ', array_map(fn (string $c) => "(CASE WHEN {$c} IS NOT NULL THEN 1 ELSE 0 END)", $terms)).' = 1)';
    }

    /** Verification is pending (no decider), verified (decider, time) or rejected (decider, time, closed reason). */
    private function verificationChecks(string $table): string
    {
        return "ALTER TABLE {$table}
            ADD CONSTRAINT {$table}_verification_status_check CHECK (verification_status IS NULL OR verification_status IN ('pending', 'verified', 'rejected')),
            ADD CONSTRAINT {$table}_rejection_reason_check CHECK (rejection_reason IS NULL OR rejection_reason IN (".self::REJECTION_REASONS.')),
            ADD CONSTRAINT '.$table."_verification_shape_check CHECK (
                (verification_status IS DISTINCT FROM 'verified' AND verification_status IS DISTINCT FROM 'rejected' AND verified_by_user_id IS NULL AND verified_at IS NULL AND rejection_reason IS NULL)
                OR (verification_status = 'verified' AND verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL AND rejection_reason IS NULL)
                OR (verification_status = 'rejected' AND verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL AND rejection_reason IS NOT NULL))";
    }
};
