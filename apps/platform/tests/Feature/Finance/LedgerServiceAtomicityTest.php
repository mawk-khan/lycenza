<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.2 closure correction: proves LedgerService's atomicity with
 * REAL injected failures at each step of the write sequence -- not
 * merely the "wrap a successful call in an outer transaction that
 * throws afterward" simulation `LedgerServicePostTest`/
 * `LedgerServiceReverseTest` use for their own (still valid, but
 * weaker) rollback tests. An outer-transaction wrap proves the service
 * PARTICIPATES in the caller's transaction; it does not prove that a
 * failure INSIDE one specific step (the line insert itself, the audit
 * write itself, the outbox write itself) actually rolls back the
 * OTHER steps that already "succeeded" earlier in the same database
 * transaction. This file proves that directly, using a genuine
 * PostgreSQL failure at each point.
 *
 * Mechanism: `withFailingTrigger()` creates a TEMPORARY, uniquely-named
 * `BEFORE INSERT` trigger (via the `pgsql_admin` connection -- DDL
 * requires that elevated connection, matching rule 54's established
 * pattern) on the target table that unconditionally `RAISE EXCEPTION`s,
 * runs the given callback, then removes the trigger and its function
 * in a `finally` block regardless of outcome -- no production schema,
 * migration, or committed Finance table definition is touched; this is
 * pure test-only DDL, fully isolated to this test file, guaranteed
 * cleaned up even if the test itself fails.
 *
 * Deliberately does NOT use DatabaseTransactions ($connectionsToTransact
 * = []), for a real reason discovered while building this file, not
 * merely for consistency with the concurrency tests: `CREATE TRIGGER`
 * requires a SHARE ROW EXCLUSIVE lock, which conflicts with the
 * ROW EXCLUSIVE lock any earlier INSERT into the same table already
 * holds for the remainder of an open transaction. Under the ordinary
 * DatabaseTransactions wrapper, a test that first creates a real
 * journal entry (e.g. via `postBalancedJournalEntry()`, which inserts
 * into `journal_lines`) and THEN tries to install a failing trigger ON
 * `journal_lines` via the SEPARATE `pgsql_admin` connection would
 * DEADLOCK against its own still-open transaction on the `pgsql`
 * connection -- the same single PHP process can never release that
 * lock (by committing/rolling back) while it is itself blocked waiting
 * on the `pgsql_admin` statement. Disabling the wrapper so every
 * statement genuinely commits immediately removes this self-deadlock
 * entirely. Manual cleanup (deleting the created School, which
 * cascades) replaces the automatic rollback.
 */
class LedgerServiceAtomicityTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades ledger_accounts/journal_entries/journal_lines
        }

        parent::tearDown();
    }

    private function service(): LedgerService
    {
        return app(LedgerService::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withFailingTrigger(string $table, callable $callback): mixed
    {
        $suffix = str_replace('-', '_', (string) Str::uuid());
        $functionName = "test_inject_failure_{$suffix}";
        $triggerName = "test_inject_failure_trigger_{$suffix}";

        DB::connection('pgsql_admin')->statement(
            "CREATE FUNCTION {$functionName}() RETURNS trigger AS ".
            '$body$ BEGIN RAISE EXCEPTION \'test-injected failure\'; END; $body$ '.
            'LANGUAGE plpgsql'
        );

        DB::connection('pgsql_admin')->statement(
            "CREATE TRIGGER {$triggerName} BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$functionName}()"
        );

        try {
            return $callback();
        } finally {
            DB::connection('pgsql_admin')->statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");
            DB::connection('pgsql_admin')->statement("DROP FUNCTION IF EXISTS {$functionName}()");
        }
    }

    /**
     * `DomainEventOutbox` is a platform-level table with NO
     * `SchoolScope`/RLS (unlike `JournalEntry`/`JournalLine`/
     * `SchoolAuditEvent`, which are already implicitly scoped to
     * `$this->school` by the ambient `TenantContext` this method is
     * always called under) -- it must be filtered by `school_id`
     * explicitly, or this count would include every OTHER test's
     * genuinely-committed outbox rows too (this class's
     * `$connectionsToTransact = []` means those rows are real and
     * persist in the shared isolated test database across test
     * methods/classes, exactly as ADR 0025 intends for a durable
     * outbox -- they are not bugs to roll back, just rows this
     * specific assertion must not accidentally count).
     */
    private function postingCounts(): array
    {
        return [
            JournalEntry::query()->count(),
            JournalLine::query()->count(),
            SchoolAuditEvent::query()->where('event_type', 'journal_entry.posted')->count(),
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'journal_entry.posted.v1')->count(),
        ];
    }

    #[Test]
    public function a_journal_line_insert_failure_rolls_back_the_header_and_every_other_line(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        try {
            $this->withFailingTrigger('journal_lines', function () use ($cash, $income) {
                $this->service()->post($this->school, new PostJournalEntryData('INR', 'Line failure test', [
                    new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
                    new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
                ]));
            });
            $this->fail('Expected a QueryException from the injected journal_lines failure.');
        } catch (QueryException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($this->school, fn () => $this->postingCounts());
        $this->assertSame([0, 0, 0, 0], $counts, 'A journal_lines insert failure must leave zero entries, lines, audit events, and outbox events.');
    }

    #[Test]
    public function an_audit_recording_failure_rolls_back_the_entire_posting(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        try {
            $this->withFailingTrigger('school_audit_events', function () use ($cash, $income) {
                $this->service()->post($this->school, new PostJournalEntryData('INR', 'Audit failure test', [
                    new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
                    new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
                ]));
            });
            $this->fail('Expected a QueryException from the injected AuditRecorder failure.');
        } catch (QueryException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($this->school, fn () => $this->postingCounts());
        $this->assertSame(
            [0, 0, 0, 0],
            $counts,
            'An AuditRecorder persistence failure must roll back the already-inserted journal entry and lines too, not merely skip the audit row.'
        );
    }

    #[Test]
    public function an_outbox_recording_failure_rolls_back_the_entire_posting_including_the_already_written_audit_event(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        try {
            $this->withFailingTrigger('domain_event_outbox', function () use ($cash, $income) {
                $this->service()->post($this->school, new PostJournalEntryData('INR', 'Outbox failure test', [
                    new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
                    new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
                ]));
            });
            $this->fail('Expected a QueryException from the injected outbox-listener failure.');
        } catch (QueryException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($this->school, fn () => $this->postingCounts());
        $this->assertSame(
            [0, 0, 0, 0],
            $counts,
            'An outbox persistence failure must roll back the journal entry, lines, AND the audit event that was already written earlier in the SAME transaction -- financial truth must not commit while required outbox recording silently fails.'
        );
    }

    #[Test]
    public function a_journal_line_insert_failure_during_reversal_leaves_the_original_untouched_and_no_reversal_artifacts(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $original = $this->postBalancedJournalEntry($this->school, $cash, $income, '55.00');

        $before = app(TenantContext::class)->withSchool($this->school, fn () => [
            'entry' => $original->toArray(),
            'lines' => $original->lines()->orderBy('id')->get()->toArray(),
        ]);

        try {
            $this->withFailingTrigger('journal_lines', function () use ($original) {
                $this->service()->reverse($original);
            });
            $this->fail('Expected a QueryException from the injected journal_lines failure during reversal.');
        } catch (QueryException) {
            // expected
        }

        $after = app(TenantContext::class)->withSchool($this->school, fn () => [
            'entry' => JournalEntry::query()->findOrFail($original->id)->toArray(),
            'lines' => JournalEntry::query()->findOrFail($original->id)->lines()->orderBy('id')->get()->toArray(),
        ]);

        $this->assertSame($before['entry'], $after['entry'], 'The original entry must be completely unaffected by a failed reversal attempt.');
        $this->assertSame($before['lines'], $after['lines'], 'The original lines must be completely unaffected by a failed reversal attempt.');

        $counts = app(TenantContext::class)->withSchool($this->school, fn () => [
            JournalEntry::query()->where('reversal_of_journal_entry_id', $original->id)->count(),
            SchoolAuditEvent::query()->where('event_type', 'journal_entry.reversed')->count(),
            // DomainEventOutbox has no SchoolScope/RLS -- must filter by
            // school_id explicitly, same reasoning as postingCounts()
            // above.
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'journal_entry.reversed.v1')->count(),
        ]);
        $this->assertSame([0, 0, 0], $counts, 'A failed reversal must leave no reversal entry, no reversal audit event, no reversal outbox event.');
    }

    /**
     * Section 7 of the closure review: proves AuditRecorder and the
     * outbox listener genuinely use the SAME connection LedgerService's
     * own ledger writes use -- not a separate connection, not
     * afterCommit, not a queued/asynchronous path. This is what makes
     * the three failure-injection tests above meaningful: if either
     * write used a different connection or ran after commit, a
     * failure there could never roll back the ledger writes at all.
     */
    #[Test]
    public function audit_and_outbox_models_use_the_same_default_connection_as_the_ledger_writes(): void
    {
        $this->assertNull((new SchoolAuditEvent)->getConnectionName(), 'SchoolAuditEvent must use the default connection (no override), same as JournalEntry/JournalLine.');
        $this->assertNull((new DomainEventOutbox)->getConnectionName(), 'DomainEventOutbox must use the default connection (no override), same as JournalEntry/JournalLine.');
        $this->assertNull((new JournalEntry)->getConnectionName(), 'JournalEntry must use the default connection.');
    }
}
