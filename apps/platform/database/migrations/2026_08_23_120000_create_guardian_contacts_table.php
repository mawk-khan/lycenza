<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A.3: Guardian contact information (email/mobile), never
     * columns directly on `guardians` -- a Guardian may have several
     * contacts (personal email, work email, primary/secondary mobile).
     *
     * Searchable PII, not plaintext: `encrypted_value` holds the
     * ciphertext (Laravel's `encrypted` Eloquent cast, APP_KEY-based,
     * non-deterministic per encryption); `lookup_hash` holds a keyed
     * HMAC-SHA-256 digest (App\Support\Privacy\ContactLookupHasher) of
     * the NORMALIZED value, scoped by School and contact type so the
     * identical email/phone in two different Schools never produces
     * the same digest. Neither column, nor any other column on this
     * table, ever holds plaintext or normalized-plaintext contact
     * information. `lookup_key_version` records which HMAC key version
     * produced `lookup_hash`, so a future key rotation can identify
     * which rows still need re-hashing without a schema change (no
     * rotation workflow exists yet).
     *
     * Same-School integrity is PostgreSQL-enforced: `guardian_id` is
     * composite-FK-protected against `guardians(id, school_id)`, the
     * same pattern StudentGuardianRelationship (Phase 1A.2) already
     * established -- a School A row can never reference a School B
     * Guardian.
     *
     * Duplication rules (deliberately NOT `unique(school_id,
     * lookup_hash)` -- two different Guardians legitimately sharing a
     * household email/phone must remain possible):
     * - `unique(school_id, guardian_id, type, lookup_hash)` prevents a
     *   redundant duplicate row on the SAME Guardian, unconditional on
     *   `is_active` (the simplest rule per the accepted brief --
     *   re-adding a value a Guardian previously deactivated means
     *   reactivating that existing row, a future mutation-service
     *   concern, not inserting a second row here).
     * - A partial unique index on `(school_id, guardian_id, type)
     *   WHERE is_primary = true AND is_active = true` -- at most one
     *   active primary contact per Guardian per type, the same
     *   "partial unique index as the concurrency-safety mechanism"
     *   pattern `academic_years_one_active_per_school` (Phase 0D) and
     *   `student_guardian_relationships_one_primary_per_student`
     *   (Phase 1A.2) already established.
     *
     * `index(school_id, type, lookup_hash)` backs the tenant/type-
     * scoped exact-match candidate lookup (section 20/32 of the
     * accepted brief) -- the same digest may legitimately match
     * multiple Guardians (household sharing), so this is a plain
     * index, never a unique one.
     */
    public function up(): void
    {
        Schema::create('guardian_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('guardian_id');
            $table->string('type');
            $table->text('encrypted_value');
            $table->string('lookup_hash', 64);
            $table->unsignedInteger('lookup_key_version')->default(1);
            $table->string('label')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'guardian_id', 'type', 'lookup_hash']);
            $table->index(['school_id', 'type', 'lookup_hash']);

            $table->foreign(['guardian_id', 'school_id'])
                ->references(['id', 'school_id'])->on('guardians')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX guardian_contacts_one_active_primary_per_type '.
            'ON guardian_contacts (school_id, guardian_id, type) '.
            'WHERE is_primary = true AND is_active = true'
        );

        TenantRls::enable('guardian_contacts');
    }

    public function down(): void
    {
        TenantRls::disable('guardian_contacts');
        Schema::dropIfExists('guardian_contacts');
    }
};
