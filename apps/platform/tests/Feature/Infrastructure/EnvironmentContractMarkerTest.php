<?php

namespace Tests\Feature\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deliberately trivial and standalone (no fixtures, no DB writes).
 * Two jobs:
 *
 *   1. A permanent regression net, run as part of every normal suite
 *      execution, asserting the resolved fail-closed testing contract
 *      documented in
 *      docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md.
 *
 *   2. The MARKER that TestEnvironmentSafetyTest's negative tests
 *      target from a real subprocess launched with deliberately
 *      hostile ambient environment (CACHE_STORE=redis,
 *      QUEUE_CONNECTION=redis, MAIL_MAILER=smtp) -- this test passing
 *      inside that subprocess is the actual proof that phpunit.xml's
 *      force="true" block plus tests/bootstrap.php's $_SERVER sync win
 *      over ambient shadowing, not just an assertion run in the
 *      already-safe outer test process.
 */
class EnvironmentContractMarkerTest extends TestCase
{
    #[Test]
    public function resolved_configuration_matches_the_fail_closed_testing_contract(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame(
            config('database.testing_database'),
            config('database.connections.pgsql.database'),
        );
        $this->assertSame(
            config('database.testing_database'),
            config('database.connections.pgsql_admin.database'),
        );
    }
}
