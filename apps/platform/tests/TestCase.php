<?php

namespace Tests;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\AssertsNavigation;

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
    use AssertsNavigation, DatabaseTransactions;

    /** Wall-clock start of this test, for committing-test cleanup below. */
    private ?CarbonImmutable $testStartedAt = null;

    /** @var array<string, mixed>|null the two connections' configuration as the test began (verified by TestDatabaseGuard at boot) */
    private ?array $bootConnections = null;

    /** @var list<string> Users this test created in-process (see ownedUserIds()) */
    private array $ownedUserIds = [];

    /** @var list<string> Roles this test created in-process (see ownedRoleIds()) */
    private array $ownedRoleIds = [];

    /**
     * SR.1 (ADR 0071 §11): the local/testing catalogue fixture seam is
     * installed once per process, through the verified admin connection,
     * before the test's transaction opens.
     */
    protected function setUpTraits()
    {
        LocalCatalogueFixtures::install();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStartedAt = CarbonImmutable::now()->subSecond();
        $this->bootConnections = config()->array('database.connections');

        User::created(function (User $user): void {
            $this->ownedUserIds[] = (string) $user->getKey();
        });
        Role::created(function (Role $role): void {
            $this->ownedRoleIds[] = (string) $role->getKey();
        });

        // Registered after DatabaseTransactions' own rollback callback, so
        // it runs once this test's uncommitted rows are gone.
        $this->beforeApplicationDestroyed(fn () => $this->purgeCommittedPlatformRoleFixtures());
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
     * SWC.5 (ADR 0073 section 20.9): a committing test (`$connectionsToTransact
     * = []`) cleans up the Users and Roles IT created -- never "every row
     * created since this test started". Another committing test may leave
     * residue on purpose (a posted payroll run, which freeze triggers keep,
     * and its preparer User); when the deterministic CI shards rebalance,
     * that test can run a second before this one, and a time-window delete
     * then sweeps up its User and fails on the foreign key (the SWC.3
     * StaffRoleGrantorRaceTest failure). Ids are recorded from the Eloquent
     * `created` event in this process; no subprocess script creates a User
     * or a Role.
     *
     * @return list<string>
     */
    protected function ownedUserIds(): array
    {
        return $this->ownedUserIds;
    }

    /** @return list<string> */
    protected function ownedRoleIds(): array
    {
        return $this->ownedRoleIds;
    }

    /**
     * Phase 0N.9 (ADR 0047 section 12): the runtime role cannot DELETE a
     * School, so a committing test cleans up its School through the
     * administrative connection -- the one sanctioned test-only path. Never
     * available to application code.
     */
    protected function deleteSchoolAsAdmin(School|string|null $school): void
    {
        if ($school === null) {
            return;
        }

        $this->purgeSchoolsAsAdmin([$school instanceof School ? $school->id : $school]);
    }

    /**
     * ORG.1A (ADR 0076 §24, V-3): audit, accounting, settlement and grant
     * history are `ON DELETE RESTRICT`, so a School can no longer be removed
     * by an ordinary cascade (by design: that is the protection). A
     * committed test fixture School is therefore removed the way
     * CreatesFinancialPeriodFixtures and PurgesCommittedHrxFixtures already
     * do it: through the migration role in replica mode (test database
     * only), every `school_id` row, then the School. Every table that
     * references a School-owned row carries `school_id` itself. Replica mode
     * skips the backlog projection triggers, so the projection rows of the
     * purged deliveries, executions and email are removed explicitly.
     *
     * @param  list<string>  $schoolIds
     */
    protected function purgeSchoolsAsAdmin(array $schoolIds): void
    {
        $schoolIds = array_values(array_filter($schoolIds));
        if ($schoolIds === []) {
            return;
        }

        $admin = DB::connection('pgsql_admin');
        if ($admin->selectOne('select current_database() as db')->db !== config('database.testing_database')) {
            throw new RuntimeException('purgeSchoolsAsAdmin() runs only against the test database (CLAUDE.md rule 50).');
        }

        $admin->transaction(function () use ($admin, $schoolIds): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");

            $projected = [];
            foreach (['automation_executions', 'communication_deliveries', 'webhook_deliveries', 'email_messages'] as $source) {
                array_push($projected, ...$admin->table($source)->whereIn('school_id', $schoolIds)->pluck('id')->all());
            }
            foreach (array_chunk($projected, 1000) as $chunk) {
                $admin->table('operational_work_backlog')->whereIn('item_id', $chunk)->delete();
            }

            $tables = $admin->select(
                "SELECT c.table_name FROM information_schema.columns c
                   JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
                  WHERE c.table_schema = 'public' AND c.column_name = 'school_id'",
            );
            foreach ($tables as $row) {
                $admin->table($row->table_name)->whereIn('school_id', $schoolIds)->delete();
            }

            $admin->table('schools')->whereIn('id', $schoolIds)->delete();
        });
    }

    /**
     * Phase 0O.1A: a root platform grant can only be provisioned across the
     * administrative database boundary, so root fixtures
     * (CreatesTenancyFixtures::createPlatformRoot(), and the demo builder's
     * Platform Admin) are COMMITTED through `pgsql_admin`. The test database
     * holds no platform role grant at rest, so after this test's rollback
     * any grant still visible is such a fixture: it is removed with its
     * provisioning audit event and its user. A committing test's own
     * tearDown() has already removed what it created.
     */
    private function purgeCommittedPlatformRoleFixtures(): void
    {
        // Never delete anything through a connection a test repointed
        // (several tests swap hosts/databases to prove guards): only the
        // unchanged, boot-verified connections, and only when the admin
        // connection positively reports the test database (CLAUDE.md rule 50).
        $connections = config()->array('database.connections');
        foreach (['pgsql', 'pgsql_admin'] as $name) {
            if (($connections[$name] ?? null) !== ($this->bootConnections[$name] ?? false)) {
                return;
            }
        }

        if (! DB::table('platform_role_assignments')->exists()) {
            return;
        }

        $admin = DB::connection('pgsql_admin');
        if ($admin->selectOne('select current_database() as db')->db !== config('database.testing_database')) {
            return;
        }

        $grants = $admin->table('platform_role_assignments')->get(['id', 'user_id', 'granted_by_user_id', 'revoked_by_user_id']);
        $users = $grants->pluck('user_id')->merge($grants->pluck('granted_by_user_id'))->merge($grants->pluck('revoked_by_user_id'))->filter()->unique()->values()->all();

        $admin->table('platform_audit_events')->whereIn('subject_id', $grants->pluck('id')->all())->delete();
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $users)->delete();
        $admin->table('platform_role_assignments')->delete();
        $admin->table('users')->whereIn('id', $users)->delete();
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

        // E21-RH.6: the runtime role no longer deletes outbox rows (retention runs as the retention identity).
        DB::connection('pgsql_admin')->table('domain_event_outbox')
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
