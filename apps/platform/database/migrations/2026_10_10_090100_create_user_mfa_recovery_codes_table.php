<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4D-P1 (Staff MFA Foundation): central/platform data,
     * same "no school_id, no RLS" reasoning as `user_mfa_factors`.
     *
     * `code_hash` uses Laravel's `hashed` cast (the same treatment as
     * `users.password` -- a recovery code is single-use/verify-only
     * and must NEVER be recoverable in plaintext once issued, unlike
     * `secret_encrypted` on UserMfaFactor which genuinely needs
     * round-tripping). Plaintext codes exist only in-memory for the
     * one API response that issues them
     * (MfaEnrollmentService::confirm()/MfaRecoveryCodeService::regenerate())
     * and are never persisted or logged.
     *
     * Single-use consumption is an ATOMIC conditional UPDATE
     * (`WHERE consumed_at IS NULL`) at the application layer -- never
     * check-then-update (CLAUDE.md rule 30's same discipline, applied
     * here for the same race-safety reason). See
     * Tests\Feature\Auth\Mfa\MfaRecoveryCodeConcurrencyTest for the
     * real two-process proof.
     *
     * Regeneration policy (ADR 0037): issuing a new set marks every
     * previously-unused code `consumed_at = now()` in the same
     * transaction (never deleted -- consumed_at is the single source
     * of truth for "no longer usable", whether by legitimate use or by
     * regeneration).
     */
    public function up(): void
    {
        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_recovery_codes');
    }
};
