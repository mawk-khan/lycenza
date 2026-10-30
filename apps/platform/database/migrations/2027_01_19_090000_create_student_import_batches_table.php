<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS.EXP-5 (OD-SIS-3): one row per COMMITTED bulk Student & Guardian
 * import -- the idempotency anchor and the School's operational history.
 *
 * - No personal data: the format version, the SHA-256 of the normalized
 *   content, counts, the actor and the time. Never the CSV, a row, a name,
 *   an email or a phone; rejected previews are never stored.
 * - `(school_id, content_digest)` is unique: the same content can be
 *   committed once per School (a retried or concurrent commit replays it;
 *   a re-upload is refused in the preview).
 * - Append-only for the runtime role (TenantRls::makeAppendOnly); forced
 *   RLS. Kept with the School (operational history, tenant lifetime).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('format_version', 40);
            $table->char('content_digest', 64);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('students_created');
            $table->unsignedInteger('guardians_created');
            $table->unsignedInteger('relationships_created');
            $table->unsignedInteger('placements_created');
            $table->unsignedInteger('campus_count');
            $table->foreignUuid('committed_by_user_id')->constrained('users');
            $table->timestampTz('committed_at')->useCurrent();

            $table->unique(['school_id', 'content_digest']);
            $table->index(['school_id', 'committed_at']);
        });

        DB::statement("ALTER TABLE student_import_batches ADD CONSTRAINT student_import_batches_format_check CHECK (format_version IN ('student_guardian_import_v1'))");
        DB::statement('ALTER TABLE student_import_batches ADD CONSTRAINT student_import_batches_counts_check CHECK (row_count >= 1 AND students_created = row_count AND placements_created = row_count AND campus_count >= 1)');

        TenantRls::enable('student_import_batches');
        TenantRls::makeAppendOnly('student_import_batches');
    }

    public function down(): void
    {
        TenantRls::disable('student_import_batches');
        Schema::dropIfExists('student_import_batches');
    }
};
