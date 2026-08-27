<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5D.3 -- Identity-domain (root CLAUDE.md architectural
     * ownership: Identity owns account provisioning/activation,
     * Communications only ever consumes the resulting AccountLink).
     * An explicit, single-use, expiring invitation for a Guardian (or,
     * structurally, a Student -- see below) domain identity to
     * establish a real School OS account.
     *
     * `token_hash` -- SHA-256 of a cryptographically random plaintext
     * token (mirroring Laravel Sanctum's own `personal_access_tokens.token`
     * hashing convention, already a dependency of this app via
     * `HasApiTokens` on `App\Models\User`) -- the plaintext token is
     * NEVER persisted anywhere, only embedded once in the one-time
     * invitation email/URL. `destination_email_hash` is a SHA-256 of
     * the normalized destination email the invitation was issued
     * to -- never the plaintext address (root CLAUDE.md rule 9-style
     * "no float for currency" discipline applied here: no decrypted
     * PII stored where a comparison hash suffices) -- re-derived and
     * compared at acceptance time so a Guardian's contact email
     * changing after issuance invalidates the match rather than
     * silently redirecting the token to a new destination (brief §29).
     *
     * `status` has exactly three REAL values (`pending`/`accepted`/
     * `revoked`) -- "expired" is deliberately NOT a fourth physical
     * status (no background job flips it); it is a derived read
     * (`expires_at < now()` while still `pending`), avoiding a second
     * source of truth for the same fact. See
     * `App\Domain\Identity\Infrastructure\GuardianAccountInvitation::isExpired()`/
     * `effectiveStatus()`.
     *
     * `num_nonnulls(guardian_id, student_id) = 1` mirrors the exact
     * convention already established by
     * `communication_domain_preferences`/`_consent_events` (Phase
     * 5D.2) and `communication_thread_participants`' provenance
     * columns (Phase 5D.1) for this identical Guardian/Student pairing
     * shape -- `student_id` exists for schema symmetry only; no
     * application code in this checkpoint ever sets it (Phase 5D.3
     * §7: Student account provisioning remains architecturally
     * deferred, never fabricated).
     *
     * One partial unique index (`giai_one_pending_per_guardian`/
     * `_student`) is the database-level "no unlimited concurrent valid
     * invitations for the same logical activation" guarantee (brief
     * §18) -- `AccountInvitationService::resend()` revokes the old row
     * in the SAME transaction as creating the new one specifically so
     * this index is never violated by a legitimate reissue.
     */
    public function up(): void
    {
        Schema::create('identity_account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('guardian_id')->nullable();
            $table->uuid('student_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('destination_email_hash', 64);
            $table->string('status')->default('pending'); // pending|accepted|revoked
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('invited_by_user_id')->constrained('users');
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('school_id');

            $table->foreign(['guardian_id', 'school_id'], 'giai_guardian_school_foreign')
                ->references(['id', 'school_id'])->on('guardians')
                ->restrictOnDelete();

            $table->foreign(['student_id', 'school_id'], 'giai_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE identity_account_invitations ADD CONSTRAINT giai_exactly_one_identity_check '.
            'CHECK (num_nonnulls(guardian_id, student_id) = 1)'
        );
        DB::statement(
            'ALTER TABLE identity_account_invitations ADD CONSTRAINT giai_status_check '.
            "CHECK (status IN ('pending', 'accepted', 'revoked'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX giai_one_pending_per_guardian ON identity_account_invitations (school_id, guardian_id) '.
            "WHERE status = 'pending' AND guardian_id IS NOT NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX giai_one_pending_per_student ON identity_account_invitations (school_id, student_id) '.
            "WHERE status = 'pending' AND student_id IS NOT NULL"
        );

        TenantRls::enable('identity_account_invitations');
    }

    public function down(): void
    {
        TenantRls::disable('identity_account_invitations');
        Schema::dropIfExists('identity_account_invitations');
    }
};
