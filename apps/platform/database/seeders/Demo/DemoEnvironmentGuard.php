<?php

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * Fail-closed guard for the local DDEV demo dataset.
 *
 * The demo dataset creates users with ONE fixed, publicly documented
 * password (docs/development/DDEV-DEMO-REVIEW.md) -- it must never be
 * able to run anywhere except a developer's own DDEV container. Two
 * independent pieces of evidence are required, never just one:
 *
 *  - Laravel's resolved environment is exactly `local`; AND
 *  - the process is inside a DDEV web container (`IS_DDEV_PROJECT=true`,
 *    set by DDEV itself) AND both authoritative database connections
 *    (`pgsql`, `pgsql_admin`) resolve to DDEV's own private `db`
 *    service/database -- never a shared or external database.
 *
 * `allowTesting()` exists ONLY so the automated suite can exercise
 * DemoDataBuilder against `school_os_test` (inside a rolled-back
 * transaction). It is still never satisfiable in production: it
 * requires `APP_ENV=testing`, which App\Providers\AppServiceProvider
 * already refuses to boot unless every destructive-capable connection
 * points at the dedicated test database (TestDatabaseGuard, CLAUDE.md
 * rule 52). The demo-reset entry point (DemoSeeder) never uses it.
 */
final class DemoEnvironmentGuard
{
    public const DDEV_DATABASE = 'db';

    public const DDEV_DATABASE_HOST = 'db';

    /**
     * @param  array<string, mixed>  $connections  config('database.connections')
     */
    public static function assertLocalDdev(string $environment, ?string $isDdevProject, array $connections): void
    {
        if ($environment !== 'local') {
            throw new RuntimeException("Demo data refused: APP_ENV is '{$environment}', the demo requires 'local'.");
        }

        if ($isDdevProject !== 'true') {
            throw new RuntimeException('Demo data refused: IS_DDEV_PROJECT is not "true" -- the demo only runs inside DDEV.');
        }

        foreach (['pgsql', 'pgsql_admin'] as $name) {
            $connection = $connections[$name] ?? null;

            if (! is_array($connection)) {
                throw new RuntimeException("Demo data refused: database connection '{$name}' is not configured.");
            }

            if (! empty($connection['url'])) {
                throw new RuntimeException("Demo data refused: connection '{$name}' uses a URL, which could point anywhere.");
            }

            if (($connection['host'] ?? null) !== self::DDEV_DATABASE_HOST || ($connection['database'] ?? null) !== self::DDEV_DATABASE) {
                throw new RuntimeException(sprintf(
                    "Demo data refused: connection '%s' resolves to %s/%s, not DDEV's private %s/%s.",
                    $name,
                    (string) ($connection['host'] ?? '?'),
                    (string) ($connection['database'] ?? '?'),
                    self::DDEV_DATABASE_HOST,
                    self::DDEV_DATABASE,
                ));
            }
        }
    }

    /**
     * DemoDataBuilder's own guard: the local DDEV demo, or the automated
     * test suite (whose database identity TestDatabaseGuard has already
     * verified at boot). Nothing else.
     *
     * @param  array<string, mixed>  $connections
     */
    public static function assertLocalDdevOrTesting(string $environment, ?string $isDdevProject, array $connections, string $testingDatabase): void
    {
        if ($environment === 'testing') {
            foreach (['pgsql', 'pgsql_admin'] as $name) {
                if (($connections[$name]['database'] ?? null) !== $testingDatabase) {
                    throw new RuntimeException("Demo data refused: testing connection '{$name}' is not the dedicated test database.");
                }
            }

            return;
        }

        self::assertLocalDdev($environment, $isDdevProject, $connections);
    }
}
