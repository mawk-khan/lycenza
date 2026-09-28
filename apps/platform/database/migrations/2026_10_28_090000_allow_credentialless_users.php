<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.12B (ADR 0059 section 4): the explicit "no credential yet" state.
 *
 * An operator-provisioned bootstrap account exists before its person has
 * chosen a password. It is represented by `users.password IS NULL` -- never
 * by a random placeholder hash, which would look like a real credential to
 * sign-in and to account recovery (a recovery link would then act as an
 * activation link).
 *
 * - `password` becomes nullable; an empty string is refused
 *   (`users_password_not_empty`), so "no credential" has exactly one form.
 * - A credential is never removed: once set, `password` can change but can
 *   never return to NULL (`trg_users_password_never_cleared`).
 * - The existing credential-version trigger already bumps the version when
 *   the first password is set (NULL IS DISTINCT FROM the new hash).
 *
 * The table is audited first: an empty-string password (none exists in any
 * supported path) makes the migration refuse, counts only.
 *
 * down(): refuses while any credential-less account exists -- restoring NOT
 * NULL would need a password this migration cannot invent, and identities are
 * never deleted by a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $empty = (int) DB::selectOne("SELECT count(*) AS c FROM users WHERE password = ''")->c;

        if ($empty > 0) {
            throw new RuntimeException("users_password: refusing -- {$empty} account(s) store an empty password; that needs an explicit identity decision (ADR 0059 section 4). No row was changed.");
        }

        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_password_not_empty CHECK (password IS NULL OR password <> '')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION users_password_never_cleared() RETURNS trigger AS $$
            BEGIN
                IF OLD.password IS NOT NULL AND NEW.password IS NULL THEN
                    RAISE EXCEPTION 'users: an established credential is never removed (users_password_never_cleared)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_users_password_never_cleared BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION users_password_never_cleared();
            SQL);
    }

    public function down(): void
    {
        $credentialless = (int) DB::selectOne('SELECT count(*) AS c FROM users WHERE password IS NULL')->c;

        if ($credentialless > 0) {
            throw new RuntimeException("users_password: cannot roll back -- {$credentialless} account(s) have no credential yet (ADR 0059 section 4). Activate or remove them deliberately first.");
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_users_password_never_cleared ON users');
        DB::statement('DROP FUNCTION IF EXISTS users_password_never_cleared()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_password_not_empty');
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
    }
};
