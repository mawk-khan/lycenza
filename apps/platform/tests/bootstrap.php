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
foreach ($_ENV as $name => $value) {
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
