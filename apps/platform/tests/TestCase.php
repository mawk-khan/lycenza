<?php

namespace Tests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * DatabaseTransactions, not RefreshDatabase -- see
 * docs/architecture/adr/0024-real-postgresql-test-infrastructure.md
 * for why the test schema is migrated once (via pgsql_admin) rather
 * than per-test, and why that ADR is why tearDown() below matters: the
 * Postgres session GUC TenantContext sets is NOT transactional, so
 * without this explicit reset, one test's School context could leak
 * into the next test sharing the same connection -- exactly the
 * long-running-worker risk section 25/32 of this checkpoint's brief
 * describes, now made real by the test suite's own connection reuse.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(TenantContext::class)->clearAll();
        }

        parent::tearDown();
    }
}
