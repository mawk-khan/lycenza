<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4D-P1 (Staff MFA Foundation): central/platform data,
     * deliberately NO `school_id` and NO RLS -- MFA belongs to User
     * identity, not any single School (a User can hold active
     * SchoolMembership rows in many Schools -- see `users`/
     * `school_memberships` migrations' own "central/platform data"
     * docblocks). v1 supports exactly one factor type, `totp`
     * (RFC 6238), enforced via a CHECK constraint -- no SMS/email/
     * WebAuthn implementation exists yet (see ADR 0037).
     *
     * `secret_encrypted` uses Laravel's `encrypted` cast (the same
     * APP_KEY AES-256-CBC pattern as `webhook_endpoints.secret_encrypted`,
     * CLAUDE.md rule 46) -- reversible, because TOTP verification
     * genuinely needs the raw secret every time a code is checked,
     * unlike a password. Never logged, never audited (see
     * MfaAuditActions and every *MfaService for the discipline this
     * column requires).
     *
     * One-active-factor-per-User (v1 policy, ADR 0037) is enforced
     * with the SAME partial-unique-index pattern
     * `academic_years_one_active_per_school` already established for
     * "exactly one active X per Y" -- a database guarantee, not a
     * controller-level check-then-insert, so two concurrent enrollment
     * confirmations for the same User cannot both end up `active` (see
     * Tests\Feature\Auth\Mfa\MfaFactorConcurrencyTest).
     */
    public function up(): void
    {
        Schema::create('user_mfa_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('totp');
            $table->text('secret_encrypted');
            $table->string('label')->nullable();
            $table->string('status')->default('pending'); // pending|active|revoked
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement("ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_type_check CHECK (type IN ('totp'))");
        DB::statement("ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_status_check CHECK (status IN ('pending', 'active', 'revoked'))");
        DB::statement("CREATE UNIQUE INDEX user_mfa_factors_one_active_per_user ON user_mfa_factors (user_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_factors');
    }
};
