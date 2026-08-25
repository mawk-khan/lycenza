<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0E.1 -- the shared Documents module's foundation table
     * (ADR 0012, docs/modules/DOCUMENTS.md). Stores METADATA ONLY --
     * this migration and this checkpoint never write file bytes to any
     * disk. `storage_disk`/`storage_path` describe where a file already
     * lives or will live; `storage_path` is always derived via
     * App\Support\Tenancy\TenantStoragePath::for($school, $fragment)
     * by any future writer, never a caller-supplied final value (rule
     * 23) -- this checkpoint ships no write service, so that rule is
     * enforced by the next checkpoint that adds one, not by this
     * migration.
     *
     * Owning-entity reference ("exclusive arc"): `employee_id`/
     * `student_id`/`guardian_id` are all nullable, each with its own
     * composite foreign key to (id, school_id) on its owning table --
     * exactly the structural, database-enforced pattern CLAUDE.md rule
     * 70 already established for Academic Structure/HR (a bare
     * polymorphic owner_type+owner_id column, ADR 0012's own example
     * phrasing notwithstanding, cannot carry a real foreign key against
     * more than one parent table -- a "current main implementation
     * state" constraint this checkpoint must respect, not a stylistic
     * preference). The CHECK constraint below enforces exactly one
     * owner column is set; a Document with zero or multiple owners is
     * rejected at the database level, not just by application
     * discipline. `invoice_id` (ADR 0012's fourth named example) is
     * deliberately NOT included -- no `invoices` table exists anywhere
     * in this repository yet (Finance/Phase 0G is unstarted); adding a
     * column that cannot reference anything real would be exactly the
     * speculative-field pattern CLAUDE.md rule 2 forbids. Adding a new
     * owner arm is a small, additive, forward-compatible migration
     * whenever a real owner table exists -- never a blocking reason to
     * guess ahead now.
     *
     * `classification_tier` uses the CANONICAL four-tier vocabulary
     * from docs/security/DATA-CLASSIFICATION.md (`public`/`internal`/
     * `sensitive`/`highly_sensitive`) -- deliberately NOT
     * `employee_documents.classification_tier`'s narrower
     * `restricted`/`highly_sensitive`-only vocabulary (Phase 8A.7's own
     * HR-specific choice, appropriate for HR's Sensitive-or-higher
     * baseline but not for a shared module that must also be able to
     * hold a Public school event photo). No default value -- every
     * insert must explicitly state a document's real classification,
     * never inherit a blanket default
     * (docs/security/DATA-CLASSIFICATION.md's "Documents/files" row:
     * "the Documents module (ADR 0012) must tag each stored document
     * with its actual classification, not a blanket default").
     *
     * `status` (active|archived, default active, never hard-deleted)
     * matches the exact reference-entity-lifecycle convention rule 73
     * already established (GradeLevel/Department/Position/.../
     * EmployeeDocument) -- a Document row is never physically deleted
     * once created, only archived, since other modules may hold
     * historical references to it.
     *
     * Deliberately NOT included in this foundation checkpoint (all
     * explicitly deferred, not silently forgotten -- see
     * docs/modules/DOCUMENTS.md "Deferred / out of scope"):
     * download/signed-URL authorization, an upload/create service, any
     * HTTP/API surface, capabilities, audit wiring, retention/
     * expiry policy, malware scanning, checksum/content-hash, and any
     * reconciliation of `employee_documents` into this table (that
     * reconciliation step was explicitly promised, not performed, by
     * ADR 0028/docs/modules/HR.md -- Phase 8A is published and closed
     * and this checkpoint does not reopen it).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            $table->uuid('employee_id')->nullable();
            $table->uuid('student_id')->nullable();
            $table->uuid('guardian_id')->nullable();

            $table->string('classification_tier'); // public|internal|sensitive|highly_sensitive -- see CHECK below
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->string('status')->default('active'); // active|archived
            $table->timestamps();

            $table->index('school_id');
            $table->index('employee_id');
            $table->index('student_id');
            $table->index('guardian_id');
            $table->index(['school_id', 'classification_tier']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();
            $table->foreign(['guardian_id', 'school_id'])
                ->references(['id', 'school_id'])->on('guardians')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_classification_tier_check '.
            "CHECK (classification_tier IN ('public', 'internal', 'sensitive', 'highly_sensitive'))"
        );

        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_status_check '.
            "CHECK (status IN ('active', 'archived'))"
        );

        // Exclusive arc: exactly one of employee_id/student_id/guardian_id
        // must be set. Structural enforcement, not application discipline
        // -- see this migration's own docblock above.
        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_owner_check '.
            'CHECK ('.
            '(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN student_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN guardian_id IS NOT NULL THEN 1 ELSE 0 END) = 1'.
            ')'
        );

        TenantRls::enable('documents');
    }

    public function down(): void
    {
        TenantRls::disable('documents');
        Schema::dropIfExists('documents');
    }
};
