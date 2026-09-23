<?php

namespace Tests;

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * DatabaseTransactions, not RefreshDatabase -- see
 * docs/architecture/adr/0024-real-postgresql-test-infrastructure.md
 * for why the test schema is migrated once (via pgsql_admin) rather
 * than per-test, and why that ADR is why tearDown() below matters: a
 * PostgreSQL session-level `set_config(..., false)` GUC persists past
 * COMMIT, so without this explicit reset, one test's School context
 * could leak into the next test sharing the same connection -- exactly
 * the long-running-worker risk section 25/32 of this checkpoint's
 * brief describes, now made real by the test suite's own connection
 * reuse.
 *
 * This MUST run BEFORE `parent::tearDown()` -- Laravel's base tearDown
 * flushes and nulls `$this->app` as part of its own cleanup (see
 * `InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment()`), so
 * the database/container are no longer usable once `parent::tearDown()`
 * returns; there is no way to run cleanup AFTER it.
 *
 * Uses `clearAllTolerantly()`, not `clearAll()` -- see
 * docs/architecture/TENANCY.md ("Root cause: aborted-transaction
 * cleanup"). A test can leave the shared connection's transaction in
 * PostgreSQL's aborted state (e.g. a raw, unwrapped constraint-
 * violating write --
 * `tests/Feature/Tenancy/TenantContextAbortedTransactionTest.php`'s
 * "unwrapped" group is exactly this shape). `clearAll()`'s own RESET
 * statement would itself fail with SQLSTATE 25P02 while the connection
 * is still aborted, and since this tearDown() has no reliable way to
 * know whether the JUST-FINISHED test passed or failed, it cannot use
 * withSchool()'s richer exception-aware restore split either. Before
 * this fix, an exception thrown here (from `clearAll()`) meant this
 * tearDown() itself aborted, so DatabaseTransactions' OWN rollback
 * (registered as a `beforeApplicationDestroyed` callback, run inside
 * `parent::tearDown()` -- which never got a chance to execute) never
 * ran -- leaving the SAME poisoned connection for whichever test
 * happened to run next, the exact mechanism behind the observed
 * full-suite ordering flakiness. `clearAllTolerantly()` instead logs a
 * warning and continues for that one, specific, proven-safe SQLSTATE,
 * so this tearDown() always completes and DatabaseTransactions' own
 * rollback (which reverts the GUC automatically -- a session-level
 * `set_config` is still reverted by ROLLBACK, it only persists past
 * COMMIT) always gets to run next.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /** Wall-clock start of this test, for committing-test cleanup below. */
    private ?CarbonImmutable $testStartedAt = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStartedAt = CarbonImmutable::now()->subSecond();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(TenantContext::class)->clearAllTolerantly();
            $this->purgeOutboxResidueAfterCommittingTest();
        }

        parent::tearDown();
    }

    /**
     * Tests that opt out of DatabaseTransactions (`$connectionsToTransact
     * = []`, e.g. the real two-process concurrency proofs) COMMIT their
     * data. Most then delete their School; some cannot (a School holding
     * an approved/posted payroll run is protected by freeze triggers, by
     * design), so their rows stay as test-database residue.
     * `domain_event_outbox` deliberately has no FK to `schools` (ADR
     * 0025), so either way those Schools' events were left permanently
     * `pending` -- and `platform:outbox-dispatch` claims the OLDEST pending
     * rows first (batch 100), so once enough residue accumulated, a later
     * test's own event was never claimed (the root cause of the
     * order-dependent Webhooks/EndToEndProofBTest and WebhookReliability
     * failures). Removes the outbox rows of Schools that no longer exist,
     * or that THIS test created (every committing test creates fresh
     * Schools); a School that existed before this test is never touched.
     */
    private function purgeOutboxResidueAfterCommittingTest(): void
    {
        if (! property_exists($this, 'connectionsToTransact') || $this->connectionsToTransact !== [] || $this->testStartedAt === null) {
            return;
        }

        $startedAt = $this->testStartedAt;

        DB::table('domain_event_outbox')
            ->whereNotNull('school_id')
            ->where(fn (Builder $query) => $query
                ->whereNotExists(fn (Builder $schools) => $schools
                    ->selectRaw('1')
                    ->from('schools')
                    ->whereColumn('schools.id', 'domain_event_outbox.school_id'))
                ->orWhereIn('school_id', fn (Builder $schools) => $schools
                    ->select('id')
                    ->from('schools')
                    ->where('created_at', '>=', $startedAt)))
            ->delete();
    }
}
