<?php

namespace App\Support\Testing;

use App\Support\Testing\Exceptions\UnsafeTestDatabaseException;

/**
 * Phase 0C.3A safety closure. The incident this exists to make hard to
 * repeat: `APP_ENV=testing php artisan migrate:fresh` (and
 * `db:seed`) silently resolved to the ordinary DEVELOPMENT database
 * and reseeded it, because `APP_ENV=testing` alone does not change
 * `DB_DATABASE` -- this project deliberately has no `.env.testing`
 * file (a second, driftable copy of connection config is exactly the
 * kind of duplicate configuration system CLAUDE.md warns against), so
 * a plain `artisan` invocation with `--env=testing` (as opposed to
 * `php artisan test`, whose DB_* overrides come from phpunit.xml's
 * <env> block) falls back to plain `.env`'s development database
 * unless the caller ALSO explicitly overrides DB_DATABASE (and, for
 * the migration/admin connection, potentially independently).
 *
 * This is a fail-closed INVARIANT, not a warning: whenever Laravel
 * believes it is running in the `testing` environment, EVERY
 * connection that could perform a destructive operation
 * (`pgsql` -- the runtime connection nothing should treat as safe to
 * wipe; `pgsql_admin` -- the migration/admin connection that actually
 * CAN wipe a database) must resolve to the one approved test database
 * name (`config('database.testing_database')`, currently
 * `school_os_test`) or the application refuses to boot at all. Uses
 * exact string equality against one explicit config value -- never
 * fragile substring/prefix matching -- per section 4 of the 0C.3A
 * brief.
 *
 * Deliberately config-only: this check never opens a database
 * connection itself (section 5 -- "must not require a database
 * connection merely to evaluate configuration"), so it is cheap
 * enough to run unconditionally on every boot and imposes zero cost
 * outside the `testing` environment, where it does nothing at all.
 */
class TestDatabaseGuard
{
    /**
     * Every connection capable of a destructive migration/seed
     * operation. `sqlite`/`mysql`/`mariadb`/`sqlsrv` are not used by
     * this project's real database layer (Postgres-only, ADR 0003) and
     * are intentionally excluded.
     *
     * @var array<int, string>
     */
    private const GUARDED_CONNECTIONS = ['pgsql', 'pgsql_admin'];

    public function assertSafe(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $approved = config('database.testing_database');

        foreach (self::GUARDED_CONNECTIONS as $connection) {
            $resolved = config("database.connections.{$connection}.database");

            if ($resolved !== $approved) {
                throw new UnsafeTestDatabaseException(
                    "Refusing to boot: APP_ENV=testing but connection '{$connection}' resolves to database ".
                    var_export($resolved, true)." (config('database.connections.{$connection}.database')), ".
                    "not the approved test database '{$approved}' (config('database.testing_database')). ".
                    'APP_ENV=testing alone does not guarantee DB_DATABASE points at the test database -- '.
                    'this project has no .env.testing file, so a plain artisan invocation with --env=testing '.
                    "falls back to plain .env's ordinary development database unless DB_DATABASE (and, for ".
                    'pgsql_admin, its admin credentials) are explicitly overridden for this invocation. Use '.
                    "'php artisan platform:test-db-reset' (which verifies this same invariant before doing ".
                    'anything destructive) instead of a raw migrate:fresh/db:seed. See CLAUDE.md.'
                );
            }
        }
    }
}
