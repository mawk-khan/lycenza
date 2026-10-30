<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS.EXP-3 (OD-SIS-1, owner-approved 2026-10-30): the Student-record side of
 * a Student-owned Document. The bytes, content validation, storage key,
 * secure retrieval and the file's own `status` stay in Documents
 * (`documents`, ADR 0012); this table holds only what SIS owns -- the
 * code-owned category, a plain-text title, an optional document date and
 * the record's archive (reason, time, actor).
 *
 * - One row per Student-owned Document (`document_id` unique). The
 *   composite key `(document_id, student_id, school_id)` -> `documents`
 *   makes the Document's own owner arm name the SAME Student in the SAME
 *   School (rule 70), so a relation can never point at another Student's or
 *   School's file; `(student_id, school_id)` -> `students` likewise.
 * - The category and archive reason CHECKs list exactly the codes of
 *   StudentDocumentCategory / StudentDocumentArchiveReason
 *   (StudentDocumentArchitectureTest keeps them equal). No tenant-defined
 *   type exists.
 * - `form_key` is the server-issued upload form key: unique per School, so
 *   a retried upload can never create a second record (rule 30: the
 *   constraint is the guarantee, never a pre-check). `upload_fingerprint`
 *   (SHA-256 over the category, title, date and the file's own SHA-256)
 *   binds the key to its content: a reused key with different content is
 *   refused, never silently answered with the first upload.
 * - Archive is final and identity is frozen (trigger below); the runtime
 *   role cannot DELETE this table. A row goes only with its Document or
 *   Student (both keys cascade): the only deleter of either is the Student's
 *   own retention purge (E21.2D, the D7 core record). The runtime role keeps
 *   its existing DELETE on `documents` (Documents' generic purge seam), so
 *   "no hard delete" is enforced by the absence of any delete path in code,
 *   not by a privilege on `documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The target of the same-Student composite key below.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_student_school_unique UNIQUE (id, student_id, school_id)');

        Schema::create('student_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('document_id');
            $table->string('category', 40);
            $table->string('title', 120);
            $table->date('document_date')->nullable();
            $table->uuid('form_key');
            $table->char('upload_fingerprint', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by_user_id')->nullable()->constrained('users');
            $table->string('archive_reason', 40)->nullable();
            $table->timestampsTz();

            $table->unique('document_id');
            $table->unique(['school_id', 'form_key']);
            $table->index(['school_id', 'student_id']);
            $table->foreign(['student_id', 'school_id'])->references(['id', 'school_id'])->on('students')->cascadeOnDelete();
            $table->foreign(['document_id', 'student_id', 'school_id'])->references(['id', 'student_id', 'school_id'])->on('documents')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_check CHECK (category IN ('enrollment_support', 'prior_school_record', 'consent_form', 'correspondence'))");
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_archive_reason_check CHECK (archive_reason IS NULL OR archive_reason IN ('uploaded_in_error', 'superseded', 'no_longer_required'))");
        DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_title_check CHECK (title = btrim(title) AND char_length(title) BETWEEN 1 AND 120 AND title !~ '[[:cntrl:]]')");
        DB::statement('ALTER TABLE student_documents ADD CONSTRAINT student_documents_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE student_documents ADD CONSTRAINT student_documents_archive_shape_check CHECK (
            (archived_at IS NULL AND archived_by_user_id IS NULL AND archive_reason IS NULL)
            OR (archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL AND archive_reason IS NOT NULL))');

        // Identity never changes, an archived record never changes again, and
        // every edit advances the version (the stale-form check's anchor).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
            CREATE TRIGGER trg_student_documents_guard BEFORE UPDATE ON student_documents
                FOR EACH ROW EXECUTE FUNCTION student_documents_guard();
            SQL);

        TenantRls::enable('student_documents');
        DB::statement('REVOKE DELETE ON student_documents FROM school_os_app');
    }

    public function down(): void
    {
        TenantRls::disable('student_documents');
        Schema::dropIfExists('student_documents');
        DB::unprepared('DROP FUNCTION IF EXISTS student_documents_guard()');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_id_student_school_unique');
    }
};
