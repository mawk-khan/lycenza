<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.12B (ADR 0059 sections 5, 10, 18): the one-time credential that
 * lets a credential-less, operator-provisioned bootstrap account set its
 * first password. IDENTITY-level security infrastructure -- no School, no
 * RLS (exactly like `account_recovery_requests`). Written only by
 * App\Domain\Identity\Application\Staff (architecture-tested); no HTTP
 * endpoint lists it.
 *
 * - Only the SHA-256 of the 256-bit secret is stored; the secret was shown
 *   once on the operator's terminal, in a link's URL FRAGMENT.
 * - `credential_version` snapshots the User at issuance.
 * - Lifetime at most 72 hours (the command defaults to 24).
 * - At most ONE open credential per User: a re-issue supersedes the
 *   previous one in the same transaction.
 * - Ending is one-way; the credential's identity never changes.
 * - Any credential-generation change of the User (the first password, an
 *   email change, a disable) ends every open activation credential,
 *   whoever made the change (trg_users_invalidate_account_activation).
 *
 * Rows are technical credential data, pruned 24 h after they end
 * (`platform:staff-account-credentials-prune`); the security audit lives in
 * `platform_audit_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_activation_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('selector', 22)->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('credential_version');
            $table->string('created_via', 16);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 32)->nullable();

            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_selector_check CHECK (selector ~ '^[A-Za-z0-9_-]{22}$')");
        DB::statement("ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_hash_check CHECK (secret_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_via_check CHECK (created_via IN ('console'))");
        DB::statement("ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_lifetime_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '72 hours')");
        DB::statement('ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_end_check CHECK (consumed_at IS NULL OR invalidated_at IS NULL)');
        DB::statement("ALTER TABLE account_activation_credentials ADD CONSTRAINT account_activation_credentials_reason_check CHECK ((invalidated_at IS NULL) = (invalidation_reason IS NULL) AND (invalidation_reason IS NULL OR invalidation_reason IN ('superseded', 'credential_changed', 'account_ineligible')))");
        DB::statement('CREATE UNIQUE INDEX account_activation_credentials_one_open ON account_activation_credentials (user_id) WHERE consumed_at IS NULL AND invalidated_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_activation_credentials_guard_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.selector <> OLD.selector OR NEW.user_id <> OLD.user_id
                    OR NEW.secret_hash <> OLD.secret_hash OR NEW.credential_version <> OLD.credential_version
                    OR NEW.created_via <> OLD.created_via OR NEW.created_at <> OLD.created_at OR NEW.expires_at <> OLD.expires_at THEN
                    RAISE EXCEPTION 'account_activation_credentials: a credential never changes (account_activation_credentials_immutable)';
                END IF;
                IF (OLD.consumed_at IS NOT NULL OR OLD.invalidated_at IS NOT NULL)
                    AND (NEW.consumed_at IS DISTINCT FROM OLD.consumed_at OR NEW.invalidated_at IS DISTINCT FROM OLD.invalidated_at) THEN
                    RAISE EXCEPTION 'account_activation_credentials: an ended credential stays ended (account_activation_credentials_ended)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_account_activation_credentials_guard_update BEFORE UPDATE ON account_activation_credentials
                FOR EACH ROW EXECUTE FUNCTION account_activation_credentials_guard_update();

            CREATE OR REPLACE FUNCTION users_invalidate_account_activation() RETURNS trigger AS $$
            BEGIN
                IF NEW.credential_version <> OLD.credential_version THEN
                    UPDATE account_activation_credentials
                    SET invalidated_at = now(),
                        invalidation_reason = CASE
                            WHEN NEW.is_disabled AND NOT OLD.is_disabled THEN 'account_ineligible'
                            ELSE 'credential_changed' END
                    WHERE user_id = NEW.id AND consumed_at IS NULL AND invalidated_at IS NULL;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_users_invalidate_account_activation AFTER UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION users_invalidate_account_activation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_users_invalidate_account_activation ON users');
        DB::statement('DROP FUNCTION IF EXISTS users_invalidate_account_activation()');
        DB::statement('DROP TRIGGER IF EXISTS trg_account_activation_credentials_guard_update ON account_activation_credentials');
        DB::statement('DROP FUNCTION IF EXISTS account_activation_credentials_guard_update()');
        Schema::dropIfExists('account_activation_credentials');
    }
};
