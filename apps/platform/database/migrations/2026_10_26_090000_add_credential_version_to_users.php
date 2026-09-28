<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.10A (ADR 0056 sections 4.4 and 11).
 *
 * 1. The canonical email form (trim + lowercase, EmailNormalizer::canonical)
 *    becomes a DATABASE guarantee: `users_email_canonical_check`. The table
 *    is AUDITED first -- if any row is not canonical, or two rows differ
 *    only by case/whitespace, the migration refuses (counts only, never an
 *    address) instead of rewriting identities.
 *
 * 2. `users.credential_version`: the per-User security generation. Every
 *    authenticated session stores it (App\Support\Auth\CredentialSession) and
 *    App\Http\Middleware\EnforceCredentialVersion signs out any session
 *    whose stamp differs -- on every host. The trigger makes it
 *    unbypassable: it never decreases, and ANY change of password, email,
 *    or a User becoming disabled bumps it (also for a direct operator
 *    write), which also invalidates every outstanding recovery credential
 *    (their snapshot no longer matches).
 */
return new class extends Migration
{
    public function up(): void
    {
        $nonCanonical = (int) DB::selectOne('SELECT count(*) AS c FROM users WHERE email <> lower(btrim(email))')->c;
        $collisions = (int) DB::selectOne('SELECT count(*) AS c FROM (SELECT lower(btrim(email)) FROM users GROUP BY 1 HAVING count(*) > 1) d')->c;

        if ($nonCanonical > 0 || $collisions > 0) {
            throw new RuntimeException("users_email_canonical: refusing -- {$nonCanonical} non-canonical email row(s) and {$collisions} case-insensitive collision group(s) need an explicit identity decision (ADR 0056 section 4.4). No row was changed.");
        }

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_canonical_check CHECK (email = lower(btrim(email)))');
        DB::statement('ALTER TABLE users ADD COLUMN credential_version bigint NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_credential_version_positive CHECK (credential_version >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION users_credential_version_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.credential_version < OLD.credential_version THEN
                    RAISE EXCEPTION 'users: credential_version never decreases (users_credential_version)';
                END IF;

                IF (NEW.password IS DISTINCT FROM OLD.password
                        OR NEW.email IS DISTINCT FROM OLD.email
                        OR (NEW.is_disabled AND NOT OLD.is_disabled))
                    AND NEW.credential_version = OLD.credential_version THEN
                    NEW.credential_version := OLD.credential_version + 1;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_users_credential_version_guard BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION users_credential_version_guard();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_users_credential_version_guard ON users');
        DB::statement('DROP FUNCTION IF EXISTS users_credential_version_guard()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_credential_version_positive');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS credential_version');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_canonical_check');
    }
};
