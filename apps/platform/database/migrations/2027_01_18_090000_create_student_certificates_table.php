<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS.EXP-4 (OD-SIS-2, owner-approved 2026-10-30): Leaving and Transfer
 * certificates -- an immutable, numbered issuance record of a Student's exit,
 * with its generated PDF stored as a Student Document.
 *
 * - `student_certificate_sequences`: one forward-only counter per School and
 *   certificate type (StudentNumberAllocator's pattern). A number is never
 *   reused; a voided certificate keeps its number; gaps are acceptable.
 * - `student_certificates`: the issuance snapshot as bounded columns (no
 *   JSON), the canonical serial (unique per School + type) and its display
 *   number (DB-derived, so it can never disagree), the final placement it
 *   certifies (`source_enrollment_id`, composite key to the SAME Student) and
 *   its generated Document (composite key to the SAME Student and School).
 * - One ISSUED certificate per Student, type and final placement (partial
 *   unique index). A correction voids the old one and issues a new one that
 *   names it (`supersedes_certificate_id`, unique, same Student, type and
 *   exit, and only a certificate voided as replaced).
 * - The fresh-MFA time of an issue or void is recorded on its audit event
 *   (who acted is an application boundary, rule 102); the row records who
 *   and when.
 * - The trigger freezes every column except the single ISSUED -> VOIDED
 *   transition; the runtime role cannot DELETE. Rows go only with their
 *   Student in the D7 core purge (every parent key cascades).
 * - `student_documents` gains the generated `issued_certificate` category
 *   (never uploadable; its category is frozen) and the `certificate_voided`
 *   archive reason.
 */
return new class extends Migration
{
    private const OLD_GUARD = <<<'SQL'
        CREATE OR REPLACE FUNCTION student_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.student_id IS DISTINCT FROM OLD.student_id
               OR NEW.document_id IS DISTINCT FROM OLD.document_id OR NEW.form_key IS DISTINCT FROM OLD.form_key OR NEW.upload_fingerprint IS DISTINCT FROM OLD.upload_fingerprint
               OR NEW.uploaded_by_user_id IS DISTINCT FROM OLD.uploaded_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'student_documents identity is immutable' USING ERRCODE = 'check_violation';
            END IF;
            IF OLD.archived_at IS NOT NULL THEN
                RAISE EXCEPTION 'an archived Student document is final' USING ERRCODE = 'check_violation';
            END IF;
            IF NEW.version <> OLD.version + 1 THEN
                RAISE EXCEPTION 'a Student document change advances its version by one' USING ERRCODE = 'check_violation';
            END IF;
            RETURN NEW;
        END $$;
        SQL;

    public function up(): void
    {
        Schema::create('student_certificate_sequences', function (Blueprint $table) {
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('certificate_type', 20);
            $table->unsignedInteger('last_serial')->default(0);
            $table->timestampsTz();
            $table->primary(['school_id', 'certificate_type']);
        });
        DB::statement("ALTER TABLE student_certificate_sequences ADD CONSTRAINT student_certificate_sequences_type_check CHECK (certificate_type IN ('leaving', 'transfer'))");
        TenantRls::enable('student_certificate_sequences');
        DB::statement('REVOKE DELETE ON student_certificate_sequences FROM school_os_app');
        // Forward only: a counter never moves back (no number is ever reused).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_certificate_sequences_forward_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.last_serial < OLD.last_serial OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.certificate_type IS DISTINCT FROM OLD.certificate_type THEN
                    RAISE EXCEPTION 'a certificate sequence only moves forward' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_certificate_sequences_forward_only BEFORE UPDATE ON student_certificate_sequences
                FOR EACH ROW EXECUTE FUNCTION student_certificate_sequences_forward_only();
            SQL);

        Schema::create('student_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->string('certificate_type', 20);
            $table->unsignedInteger('serial');
            $table->string('display_number', 20);
            $table->string('status', 20)->default('issued');
            $table->uuid('source_enrollment_id');
            $table->uuid('document_id');
            $table->uuid('supersedes_certificate_id')->nullable();

            // The issuance snapshot (frozen).
            $table->string('student_name', 255);
            $table->string('student_number', 50);
            $table->string('school_name', 255);
            $table->string('school_legal_name', 255)->nullable();
            $table->string('school_affiliation_reference', 120)->nullable();
            $table->string('school_registration_reference', 120)->nullable();
            $table->string('campus_name', 255);
            $table->string('academic_year_name', 255);
            $table->string('grade_name', 255);
            $table->string('section_name', 255);
            $table->date('joined_on')->nullable();
            $table->date('left_on');
            $table->string('exit_reason', 40);
            $table->date('issued_on');

            $table->uuid('form_key');
            $table->char('request_fingerprint', 64);
            $table->foreignUuid('issued_by_user_id')->constrained('users');
            $table->timestampTz('issued_at')->useCurrent();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users');
            $table->string('void_reason', 40)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'certificate_type', 'serial']);
            $table->unique(['school_id', 'form_key']);
            $table->unique('document_id');
            $table->unique('supersedes_certificate_id');
            $table->index(['school_id', 'student_id']);
            $table->foreign(['student_id', 'school_id'])->references(['id', 'school_id'])->on('students')->cascadeOnDelete();
            $table->foreign(['source_enrollment_id', 'student_id'])->references(['id', 'student_id'])->on('student_enrollments')->cascadeOnDelete();
            $table->foreign(['document_id', 'student_id', 'school_id'])->references(['id', 'student_id', 'school_id'])->on('documents')->cascadeOnDelete();
            $table->foreign(['supersedes_certificate_id', 'school_id'])->references(['id', 'school_id'])->on('student_certificates')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_type_check CHECK (certificate_type IN ('leaving', 'transfer'))");
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_status_check CHECK (status IN ('issued', 'voided'))");
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_exit_reason_check CHECK (exit_reason IN ('left_school', 'transferred_out', 'completed_studies', 'guardian_request'))");
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_void_reason_check CHECK (void_reason IS NULL OR void_reason IN ('data_error', 'issued_in_error', 'replaced_by_corrected_certificate'))");
        DB::statement('ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_serial_check CHECK (serial >= 1)');
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_display_number_check CHECK (display_number = (CASE certificate_type WHEN 'leaving' THEN 'LC-' ELSE 'TC-' END) || lpad(serial::text, 6, '0'))");
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_transfer_reason_check CHECK (certificate_type <> 'transfer' OR exit_reason = 'transferred_out')");
        DB::statement('ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_dates_check CHECK ((joined_on IS NULL OR joined_on <= left_on) AND left_on <= issued_on)');
        DB::statement("ALTER TABLE student_certificates ADD CONSTRAINT student_certificates_void_shape_check CHECK (
            (status = 'issued' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
            OR (status = 'voided' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX student_certificates_one_issued_per_exit ON student_certificates (student_id, certificate_type, source_enrollment_id) WHERE status = 'issued'");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_certificates_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                prior student_certificates%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'issued' THEN
                        RAISE EXCEPTION 'a certificate is born issued' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.supersedes_certificate_id IS NOT NULL THEN
                        SELECT * INTO prior FROM student_certificates WHERE id = NEW.supersedes_certificate_id;
                        IF prior.student_id IS DISTINCT FROM NEW.student_id OR prior.certificate_type IS DISTINCT FROM NEW.certificate_type
                           OR prior.source_enrollment_id IS DISTINCT FROM NEW.source_enrollment_id
                           OR prior.status <> 'voided' OR prior.void_reason <> 'replaced_by_corrected_certificate' THEN
                            RAISE EXCEPTION 'a replacement names a certificate of the same Student, type and exit voided as replaced' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;
                -- UPDATE: only ISSUED -> VOIDED, setting the void columns; everything else is frozen.
                IF OLD.status <> 'issued' OR NEW.status <> 'voided'
                   OR (to_jsonb(NEW) - ARRAY['status', 'voided_at', 'voided_by_user_id', 'void_reason', 'updated_at'])
                      IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'voided_at', 'voided_by_user_id', 'void_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'an issued certificate is never edited; it is only voided once' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_certificates_guard BEFORE INSERT OR UPDATE ON student_certificates
                FOR EACH ROW EXECUTE FUNCTION student_certificates_guard();
            SQL);

        TenantRls::enable('student_certificates');
        DB::statement('REVOKE DELETE ON student_certificates FROM school_os_app');

        // The generated category (never uploadable; frozen) and the void archive reason.
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_category_check');
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_check CHECK (category IN ('enrollment_support', 'prior_school_record', 'consent_form', 'correspondence', 'issued_certificate'))");
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_archive_reason_check');
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_archive_reason_check CHECK (archive_reason IS NULL OR archive_reason IN ('uploaded_in_error', 'superseded', 'no_longer_required', 'certificate_voided'))");
        DB::unprepared(str_replace(
            'IF NEW.version <> OLD.version + 1 THEN',
            "IF (OLD.category = 'issued_certificate' OR NEW.category = 'issued_certificate') AND NEW.category IS DISTINCT FROM OLD.category THEN\n"
            ."        RAISE EXCEPTION 'an issued certificate document keeps its category' USING ERRCODE = 'check_violation';\n"
            ."    END IF;\n    IF NEW.version <> OLD.version + 1 THEN",
            self::OLD_GUARD,
        ));
    }

    /**
     * Restores the EXP-3 catalogue; refused (by its CHECK) while any
     * generated-certificate document exists -- deliberately: rolling back
     * would otherwise orphan issued certificates' files.
     */
    public function down(): void
    {
        DB::unprepared(self::OLD_GUARD);
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_archive_reason_check');
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_archive_reason_check CHECK (archive_reason IS NULL OR archive_reason IN ('uploaded_in_error', 'superseded', 'no_longer_required'))");
        DB::statement('ALTER TABLE student_documents DROP CONSTRAINT student_documents_category_check');
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_check CHECK (category IN ('enrollment_support', 'prior_school_record', 'consent_form', 'correspondence'))");

        TenantRls::disable('student_certificates');
        Schema::dropIfExists('student_certificates');
        DB::unprepared('DROP FUNCTION IF EXISTS student_certificates_guard()');
        TenantRls::disable('student_certificate_sequences');
        Schema::dropIfExists('student_certificate_sequences');
        DB::unprepared('DROP FUNCTION IF EXISTS student_certificate_sequences_forward_only()');
    }
};
