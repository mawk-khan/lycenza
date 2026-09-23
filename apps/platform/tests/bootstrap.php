<?php

/**
 * PHPUnit bootstrap wrapper. See
 * docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md ("The
 * $_SERVER gap") for the full incident this closes.
 *
 * PHPUnit's `<env name="..." value="..." force="true"/>` (see
 * vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php)
 * updates `getenv()`/`putenv()` and `$_ENV`, but never touches
 * `$_SERVER`. Laravel's env() helper resolves through vlucas/
 * phpdotenv's `Dotenv\Repository\RepositoryBuilder`, whose default
 * reader order is `ServerConstAdapter` (reads `$_SERVER`) before
 * `EnvConstAdapter` (reads `$_ENV`) before Laravel's own appended
 * `PutenvAdapter` (reads `getenv()`) -- so `$_SERVER` wins, and PHP's
 * CLI SAPI (`variables_order` including `E` in this project's php.ini)
 * seeds `$_SERVER`/`$_ENV` identically from the real process
 * environment at startup, before PHPUnit's force="true" logic ever
 * runs. Net effect confirmed empirically: force="true" alone left
 * config('queue.default') (and cache/mail) still resolving to the
 * container's ambient development value, not phpunit.xml's forced
 * one -- config()/env() disagreed with getenv() inside the exact same
 * process.
 *
 * By the time this file runs, PHPUnit has already applied every
 * force="true" entry from phpunit.xml's <php> block to $_ENV/getenv()
 * (confirmed: PHPUnit's own configuration handling runs before the
 * configured `bootstrap` script). $_ENV and $_SERVER started as exact
 * mirrors of the real process environment, so copying $_ENV onto
 * $_SERVER now is a safe no-op for every variable PHPUnit did not
 * force, and is exactly the correction needed for every variable it
 * did.
 */
/*
 * Explicit, test-only connection-TARGET overrides. phpunit.xml forces
 * DB_HOST/DB_PORT/DB_ADMIN_* to the docker-compose `platform` container's
 * values so that an AMBIENT variable can never silently redirect the
 * suite (e.g. a host shell's DB_HOST=127.0.0.1 reaching an unrelated
 * local PostgreSQL). Environments that legitimately reach the test
 * database elsewhere -- CI's service container on 127.0.0.1, DDEV's
 * `db` service with its own admin role -- must say so deliberately via
 * these PHPUNIT_-prefixed names, which no .env/env_file ever sets by
 * accident. Only WHERE the test database lives can be redirected:
 * DB_DATABASE, APP_ENV, the runtime role and every cache/session/queue/
 * mail value stay forced, and TestDatabaseGuard still verifies the
 * resolved database identity at boot.
 */
foreach (['DB_HOST', 'DB_PORT', 'DB_ADMIN_USERNAME', 'DB_ADMIN_PASSWORD'] as $name) {
    $override = getenv('PHPUNIT_'.$name);

    if ($override !== false && $override !== '') {
        putenv("{$name}={$override}");
        $_ENV[$name] = $override;
    }
}

foreach ($_ENV as $name => $value) {
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
