<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.10A (ADR 0056 section 6.2): one self-service password-recovery
 * credential. IDENTITY-level security infrastructure -- no School, no RLS
 * (like `users` and `user_mfa_recovery_codes`). Read and written only by
 * App\Domain\Identity\Application\AccountRecovery (architecture-tested);
 * no HTTP endpoint lists it.
 *
 * Only the SHA-256 of the 256-bit secret is stored -- never the secret, a
 * URL or a request Host. `credential_version` and `email_hash` snapshot
 * the User at issuance: any credential change or email change makes the
 * credential invalid. Rows are technical credential data, pruned 24 h
 * after they end (`platform:account-recovery-prune`); the security audit
 * lives in `platform_audit_events`, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recovery_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('selector', 22)->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('credential_version');
            $table->char('email_hash', 64);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 32)->nullable();
            $table->uuid('email_message_id')->nullable();

            $table->index(['user_id', 'expires_at']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT account_recovery_requests_selector_check CHECK (selector ~ '^[A-Za-z0-9_-]{22}$')");
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT account_recovery_requests_hash_check CHECK (secret_hash ~ '^[0-9a-f]{64}$' AND email_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT account_recovery_requests_lifetime_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '30 minutes')");
        DB::statement('ALTER TABLE account_recovery_requests ADD CONSTRAINT account_recovery_requests_end_check CHECK (consumed_at IS NULL OR invalidated_at IS NULL)');
        DB::statement("ALTER TABLE account_recovery_requests ADD CONSTRAINT account_recovery_requests_reason_check CHECK ((invalidated_at IS NULL) = (invalidation_reason IS NULL) AND (invalidation_reason IS NULL OR invalidation_reason IN ('superseded_by_reset', 'credential_changed', 'email_changed', 'account_ineligible', 'operator')))");

        // Ending is one-way: a consumed or invalidated credential never
        // becomes usable again, and its identity never changes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_recovery_requests_guard_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.selector <> OLD.selector OR NEW.user_id <> OLD.user_id
                    OR NEW.secret_hash <> OLD.secret_hash OR NEW.credential_version <> OLD.credential_version
                    OR NEW.email_hash <> OLD.email_hash OR NEW.created_at <> OLD.created_at OR NEW.expires_at <> OLD.expires_at THEN
                    RAISE EXCEPTION 'account_recovery_requests: a credential never changes (account_recovery_requests_immutable)';
                END IF;
                IF (OLD.consumed_at IS NOT NULL OR OLD.invalidated_at IS NOT NULL)
                    AND (NEW.consumed_at IS DISTINCT FROM OLD.consumed_at OR NEW.invalidated_at IS DISTINCT FROM OLD.invalidated_at) THEN
                    RAISE EXCEPTION 'account_recovery_requests: an ended credential stays ended (account_recovery_requests_ended)';
                END IF;
                IF OLD.email_message_id IS NOT NULL AND NEW.email_message_id IS DISTINCT FROM OLD.email_message_id THEN
                    RAISE EXCEPTION 'account_recovery_requests: the email link never changes (account_recovery_requests_email)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_account_recovery_requests_guard_update BEFORE UPDATE ON account_recovery_requests
                FOR EACH ROW EXECUTE FUNCTION account_recovery_requests_guard_update();

            -- Any credential-generation change of a User (password, email,
            -- disable -- see users_credential_version_guard) ends every
            -- credential still outstanding, whoever made the change. The
            -- recovery reset marks its own and the superseded ones first.
            CREATE OR REPLACE FUNCTION users_invalidate_account_recovery() RETURNS trigger AS $$
            BEGIN
                IF NEW.credential_version <> OLD.credential_version THEN
                    UPDATE account_recovery_requests
                    SET invalidated_at = now(),
                        invalidation_reason = CASE
                            WHEN NEW.is_disabled AND NOT OLD.is_disabled THEN 'account_ineligible'
                            WHEN NEW.email IS DISTINCT FROM OLD.email THEN 'email_changed'
                            ELSE 'credential_changed' END
                    WHERE user_id = NEW.id AND consumed_at IS NULL AND invalidated_at IS NULL;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_users_invalidate_account_recovery AFTER UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION users_invalidate_account_recovery();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_users_invalidate_account_recovery ON users');
        DB::statement('DROP FUNCTION IF EXISTS users_invalidate_account_recovery()');
        DB::statement('DROP TRIGGER IF EXISTS trg_account_recovery_requests_guard_update ON account_recovery_requests');
        DB::statement('DROP FUNCTION IF EXISTS account_recovery_requests_guard_update()');
        Schema::dropIfExists('account_recovery_requests');
    }
};
