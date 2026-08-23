<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.7 -- an Employee's 1:N Restricted-tier document metadata
     * (docs/modules/HR.md "Documents -- narrow scope, not a parallel
     * system"; ADR 0028's "Accepted cost"). This table stores METADATA
     * ONLY, never file bytes -- confirmed by dependency discovery
     * before this migration was written: no shared Documents module
     * (ADR 0012 -- "Accepted" as a design decision, still unimplemented)
     * exists anywhere in this repository, on this branch or on current
     * `main` (both inspected read-only; `main` has advanced with
     * Students/Guardians work only, no Documents commits). The only
     * existing storage primitive is
     * App\Support\Tenancy\TenantStoragePath::for() -- a tenant-safe
     * path-building helper, not a Documents module (it provides no
     * document identity, metadata lifecycle, authorization, audit,
     * malware scanning, retention, or content validation).
     *
     * Per HR.md's already-committed design (written in 8A.0, not
     * invented here): a narrow, HR-scoped metadata table is the
     * accepted foundation until Phase 0E's real Documents module
     * exists, at which point `employee_documents` gets an explicit
     * reconciliation step (ADR 0028's own written obligation) -- never
     * silently duplicated or ignored.
     *
     * `category` (id_proof|address_proof|employment_contract|
     * appointment_letter|qualification_evidence|experience_evidence|
     * certification_evidence|background_check|policy_acknowledgement|
     * other) is a plain, application-validated string -- matches
     * `qualification_type`/`employment_type`'s exact convention, not a
     * Postgres enum, not a PHP enum, and deliberately not a new
     * School-configurable reference-data table (the same
     * EmployeeCategory-shaped taxonomy trap rule 2/8A.6 already
     * avoided).
     *
     * `classification_tier` is restricted to `restricted`/
     * `highly_sensitive` ONLY -- `directory` is deliberately not a
     * legal value at the database level, not merely "not the default".
     * HR documents are never ordinary broadly-visible attachments
     * (docs/security/DATA-CLASSIFICATION.md's Employee/HR baseline is
     * Sensitive-or-higher); allowing a code path to ever mark one
     * `directory` would contradict that baseline for no real benefit,
     * so the CHECK constraint below closes the possibility structurally
     * rather than relying on service-layer discipline alone.
     *
     * `storage_disk`/`storage_path` are metadata fields describing
     * WHERE a file already lives or will live -- this migration and
     * every 8A.7 service NEVER write actual file bytes to any disk.
     * `storage_path` is never a caller-supplied final value:
     * App\Domain\HR\Application\EmployeeDocumentService::register()
     * always derives it via `TenantStoragePath::for($employee->school,
     * $fragment)`, so every persisted value is tenant-prefixed and
     * traversal-safe by construction, not by convention. No upload/
     * download/delete-file-content capability exists in Phase 8A.7 --
     * see that service's docblock for the full reasoning.
     *
     * `original_filename` is metadata only -- never used to derive
     * `storage_path` (rule 23/HR.md "Original filename" reasoning
     * extended here).
     *
     * No `checksum` column -- cannot be honestly computed without this
     * checkpoint ever reading real file bytes, and adding an unused
     * placeholder column would be exactly the "field added because it
     * might be useful later" pattern CLAUDE.md rule 2 warns against.
     * No malware-scan/quarantine column for the same reason -- no
     * scanner exists anywhere in this repository; claiming one via a
     * schema column would be dishonest.
     *
     * No `document_id`/shared-Document composite FK -- there is no
     * shared `documents` table to reference (Case C: no reusable
     * Documents capability exists anywhere discovered).
     *
     * No `verification_status` -- HR.md's already-accepted
     * `employee_documents` field list does not include one, and
     * conflating "a document exists" with "the underlying credential is
     * verified" is exactly what 8A.6's `EmployeeQualification`/
     * `EmployeeCertification.verification_status` already owns
     * exclusively (rule: a file existing does not prove authenticity --
     * uploading/attaching evidence must never auto-verify a structured
     * HR record).
     *
     * `status` (active|archived, default active, never hard-deleted)
     * matches HR.md's own explicit text for this table verbatim --
     * the same reference-entity-lifecycle pattern (rule 73) already
     * used by GradeLevel/Department/Position/Subject/Room/Section/
     * SubjectOffering, not the hard-delete pattern 8A.2/8A.6's simpler
     * child records use. There is deliberately no
     * `EmployeeDocumentService::remove()` -- only `archive()`.
     *
     * `issued_on`/`expires_on` (both nullable) were not part of HR.md's
     * original 8A.0-era field list but are explicitly evaluated by this
     * checkpoint's own brief for documents that carry their own expiry
     * (a licence scan, an ID proof with an expiry date) -- added here
     * with the same nullable-date-range-CHECK convention every other
     * Phase 8A table already uses, not a new pattern.
     */
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('category');
            $table->string('classification_tier')->default('restricted'); // restricted|highly_sensitive -- see CHECK below
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status')->default('active'); // active|archived
            $table->timestamps();

            $table->index('school_id');
            $table->index('employee_id');
            $table->index(['school_id', 'expires_on']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_classification_tier_check '.
            "CHECK (classification_tier IN ('restricted', 'highly_sensitive'))"
        );

        DB::statement(
            'ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_date_range_check '.
            'CHECK (issued_on IS NULL OR expires_on IS NULL OR expires_on >= issued_on)'
        );

        TenantRls::enable('employee_documents');
    }

    public function down(): void
    {
        TenantRls::disable('employee_documents');
        Schema::dropIfExists('employee_documents');
    }
};
