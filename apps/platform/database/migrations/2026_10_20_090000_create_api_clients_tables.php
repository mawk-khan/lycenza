<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.3 (ADR 0049 sections 3-5): partner API clients and their
 * credentials.
 *
 * These are School-bound AUTHORIZATION BOOTSTRAP records, resolved while
 * authenticating a partner request -- before any TenantContext exists --
 * exactly like `school_memberships`, `school_domains` and
 * `personal_access_tokens`. They therefore carry an immutable `school_id`
 * but NO row-level security (a deliberate, documented exception,
 * guard-tested by Tests\Feature\Api\Partner\PartnerSchemaInvariantsTest).
 * They hold no tenant-domain content: after authentication the request
 * runs under that one School's ordinary TenantContext and every tenant
 * table keeps forced RLS. Management reads always filter by the trusted
 * School explicitly (App\Support\ApiClients\ApiClientService).
 *
 * Database guarantees:
 * - `school_id` is immutable on both tables, and a credential's School is
 *   its client's (composite foreign key on (id, school_id));
 * - a client's only permitted change is revocation, which is terminal;
 * - a credential stores a SHA-256 hex hash, never a secret; its expiry is
 *   required and at most 365 days after issue (owner value V4); its
 *   identity, hash and issue time never change; expiry may only shorten;
 *   revocation and supersession are set once;
 * - exactly one CURRENT credential per client (partial unique index), and a
 *   superseded credential expires at most 24 hours after supersession (the
 *   rotation overlap) -- so at most two are ever usable;
 * - no credential for a revoked client;
 * - the runtime role cannot DELETE either table (history is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('scopes');
            $table->string('status', 16)->default('active');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE api_clients
                ADD CONSTRAINT api_clients_status_check CHECK (status IN ('active', 'revoked')),
                ADD CONSTRAINT api_clients_revocation_check CHECK ((status = 'revoked') = (revoked_at IS NOT NULL)),
                ADD CONSTRAINT api_clients_scopes_check CHECK (
                    jsonb_typeof(scopes) = 'array' AND jsonb_array_length(scopes) > 0 AND NOT jsonb_exists(scopes, '*')
                ),
                ADD CONSTRAINT api_clients_name_check CHECK (length(btrim(name)) > 0)
            SQL);

        Schema::create('api_client_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('api_client_id');
            $table->uuid('school_id');
            $table->char('key_id', 16)->unique();
            $table->char('secret_hash', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign(['api_client_id', 'school_id'])->references(['id', 'school_id'])->on('api_clients')->cascadeOnDelete();
            $table->index('api_client_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE api_client_credentials
                ADD CONSTRAINT api_client_credentials_hash_check CHECK (secret_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT api_client_credentials_key_id_check CHECK (key_id ~ '^[0-9a-f]{16}$'),
                ADD CONSTRAINT api_client_credentials_lifetime_check CHECK (
                    expires_at > issued_at AND expires_at <= issued_at + interval '365 days'
                ),
                ADD CONSTRAINT api_client_credentials_overlap_check CHECK (
                    superseded_at IS NULL OR expires_at <= superseded_at + interval '24 hours'
                )
            SQL);

        DB::statement('CREATE UNIQUE INDEX api_client_credentials_one_current ON api_client_credentials (api_client_id) WHERE superseded_at IS NULL AND revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_api_client_governance() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'revoked' THEN
                    RAISE EXCEPTION 'api_clients: a revoked client is final and cannot change';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.scopes IS DISTINCT FROM OLD.scopes
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'api_clients: the School binding, identity, name and scopes are immutable; the only permitted change is revocation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_api_clients_governance
                BEFORE UPDATE ON api_clients
                FOR EACH ROW EXECUTE FUNCTION assert_api_client_governance();

            CREATE OR REPLACE FUNCTION assert_api_client_credential_governance() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NOT EXISTS (SELECT 1 FROM api_clients WHERE id = NEW.api_client_id AND status = 'active') THEN
                        RAISE EXCEPTION 'api_client_credentials: a credential can be issued only for an active client';
                    END IF;
                    IF NEW.superseded_at IS NOT NULL OR NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'api_client_credentials: a credential cannot be created superseded or revoked';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.api_client_id IS DISTINCT FROM OLD.api_client_id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.key_id IS DISTINCT FROM OLD.key_id
                    OR NEW.secret_hash IS DISTINCT FROM OLD.secret_hash
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'api_client_credentials: the School binding, identity, hash and issue time are immutable';
                END IF;
                IF NEW.expires_at > OLD.expires_at THEN
                    RAISE EXCEPTION 'api_client_credentials: an expiry can only be shortened, never extended';
                END IF;
                IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
                    RAISE EXCEPTION 'api_client_credentials: a revocation is final';
                END IF;
                IF OLD.superseded_at IS NOT NULL AND NEW.superseded_at IS DISTINCT FROM OLD.superseded_at THEN
                    RAISE EXCEPTION 'api_client_credentials: a supersession is final';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_api_client_credentials_governance
                BEFORE INSERT OR UPDATE ON api_client_credentials
                FOR EACH ROW EXECUTE FUNCTION assert_api_client_credential_governance();
            SQL);

        TenantRls::revokeDelete('api_clients');
        TenantRls::revokeDelete('api_client_credentials');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_client_credentials');
        Schema::dropIfExists('api_clients');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS assert_api_client_credential_governance();
            DROP FUNCTION IF EXISTS assert_api_client_governance();
            SQL);
    }
};
