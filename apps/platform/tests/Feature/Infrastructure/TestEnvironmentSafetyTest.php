<?php

namespace Tests\Feature\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real, subprocess-backed proof for
 * docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md's
 * fail-closed contract -- not a simulation. Every test here launches a
 * GENUINELY separate `php` process (Symfony\Component\Process, the
 * same pattern tests/Feature/Idempotency/IdempotencyRealConcurrencyTest
 * uses for real concurrency) with deliberately hostile environment
 * variables, and asserts on that subprocess's real exit code/output.
 * An in-process assertion (mocking config(), or calling
 * TestDatabaseGuard::assertSafe() directly) could not prove any of
 * this: the entire incident this guards against is that ambient
 * PROCESS environment silently wins over declared test configuration,
 * which by definition cannot be reproduced without a real second
 * process with its own real ambient environment.
 *
 * Symfony Process merges an explicit `env` array on top of the
 * current process's inherited environment (it does not replace it) --
 * exactly the "ambient plus explicit override" shape being tested.
 */
class TestEnvironmentSafetyTest extends TestCase
{
    /**
     * Item 21 / CLAUDE.md rule 50's exact incident, reproduced as a
     * proof rather than a historical anecdote: APP_ENV=testing is
     * correctly set, but the resolved `pgsql` database is the
     * ordinary development one, not the approved test database.
     * TestDatabaseGuard must refuse to let the application boot at
     * all -- proven here via platform:test-db-reset, the one command
     * that would otherwise run `migrate:fresh` against it.
     */
    #[Test]
    public function platform_test_db_reset_refuses_an_unsafe_resolved_database(): void
    {
        $process = $this->artisanSubprocess(
            ['platform:test-db-reset', '--force'],
            ['DB_DATABASE' => 'school_os'], // the real development database name
        );
        $process->run();

        $this->assertFalse($process->isSuccessful(), 'platform:test-db-reset must refuse to run against an unsafe resolved database, but it succeeded.');
        // Symfony Console's error block wraps at a fixed column WITHOUT
        // regard for word boundaries (it can insert a newline inside a
        // single word, e.g. "app\nroved"), so even whitespace-collapsing
        // is not enough -- strip all whitespace from both sides instead.
        $stripped = preg_replace('/\s+/', '', $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Refusingtoboot', $stripped);
        $this->assertStringContainsString("nottheapprovedtestdatabase'school_os_test'", $stripped);
    }

    /**
     * Item 22: APP_ENV resolves to something other than "testing".
     * platform:test-db-reset's own handle() must refuse before ever
     * consulting TestDatabaseGuard or touching the database --
     * proven independently of the database-identity check above by
     * deliberately leaving DB_DATABASE at its safe inherited value
     * here.
     */
    #[Test]
    public function platform_test_db_reset_refuses_when_app_env_is_not_testing(): void
    {
        $process = $this->artisanSubprocess(
            ['platform:test-db-reset', '--force'],
            ['APP_ENV' => 'local'],
        );
        $process->run();

        $this->assertFalse($process->isSuccessful(), 'platform:test-db-reset must refuse to run outside APP_ENV=testing, but it succeeded.');
        $this->assertStringContainsString('refuses to run outside APP_ENV=testing', $process->getErrorOutput().$process->getOutput());
    }

    /**
     * Item 23/24/25: hostile ambient CACHE_STORE/QUEUE_CONNECTION/
     * MAIL_MAILER, all three at once, run through the actual PHPUnit
     * invocation path (not a raw `php artisan` call, which force="true"
     * cannot protect -- see phpunit.xml's block comment). This is the
     * literal repro of the Phase 5 closure-blocker verification
     * incident: without force="true" and tests/bootstrap.php's
     * $_SERVER sync, EnvironmentContractMarkerTest fails under this
     * exact ambient combination.
     */
    #[Test]
    public function hostile_ambient_cache_queue_and_mail_do_not_shadow_phpunit_via_force_true(): void
    {
        $process = $this->phpunitSubprocess(
            'EnvironmentContractMarkerTest',
            [
                'CACHE_STORE' => 'redis',
                'QUEUE_CONNECTION' => 'redis',
                'MAIL_MAILER' => 'smtp',
                'SESSION_DRIVER' => 'redis',
            ],
        );
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "EnvironmentContractMarkerTest must still pass under hostile ambient CACHE_STORE/QUEUE_CONNECTION/MAIL_MAILER.\n".
            'Output: '.$process->getOutput()."\nError: ".$process->getErrorOutput()
        );
        $this->assertStringContainsString('OK (1 test', $process->getOutput());
    }

    /**
     * Same proof as above, isolated to DB_HOST specifically: a hostile
     * ambient DB_HOST (as if the process were run outside the
     * `platform` container, where `postgres` does not resolve) must
     * not leak through phpunit.xml either.
     */
    #[Test]
    public function hostile_ambient_db_host_does_not_shadow_phpunit_via_force_true(): void
    {
        $process = $this->phpunitSubprocess(
            'EnvironmentContractMarkerTest',
            ['DB_HOST' => '203.0.113.1'], // TEST-NET-3 (RFC 5737): guaranteed unreachable, never resolves
        );
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "EnvironmentContractMarkerTest must still pass under a hostile ambient DB_HOST.\n".
            'Output: '.$process->getOutput()."\nError: ".$process->getErrorOutput()
        );
    }

    /**
     * @param  array<int, string>  $artisanArgs
     * @param  array<string, string>  $hostileEnv
     */
    private function artisanSubprocess(array $artisanArgs, array $hostileEnv): Process
    {
        $process = new Process(
            array_merge(['php', 'artisan'], $artisanArgs),
            base_path(),
            $hostileEnv,
        );
        $process->setTimeout(30);

        return $process;
    }

    /**
     * @param  array<string, string>  $hostileEnv
     */
    private function phpunitSubprocess(string $filter, array $hostileEnv): Process
    {
        $process = new Process(
            ['vendor/bin/phpunit', '--filter', $filter, 'tests/Feature/Infrastructure/EnvironmentContractMarkerTest.php'],
            base_path(),
            $hostileEnv,
        );
        $process->setTimeout(60);

        return $process;
    }
}
