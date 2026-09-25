<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.4 closure correction (section 18): proves
 * `ChargeService::cancel()`'s atomicity with a REAL injected failure
 * AFTER `LedgerService::reverseById()` has already written its
 * reversal journal entry/lines/audit/outbox, but BEFORE the `charges`
 * UPDATE that would record the cancellation can commit -- mirroring
 * `Tests\Feature\Finance\LedgerServiceAtomicityTest`'s exact
 * `withFailingTrigger()` mechanism, applied to a `BEFORE UPDATE`
 * trigger on `charges` instead of a `BEFORE INSERT` one. This proves
 * the outer transaction genuinely rolls back Finance's nested
 * reversal work too, not merely that the operational `charges` row
 * fails to update.
 *
 * Deliberately does NOT use DatabaseTransactions ($connectionsToTransact
 * = []) for the identical reason `LedgerServiceAtomicityTest` documents:
 * installing a trigger via the separate `pgsql_admin` connection while
 * this test's own `pgsql` connection holds an open transaction on the
 * same table would self-deadlock.
 */
class ChargeCancellationAtomicityTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades charges/ledger_accounts/journal_entries/journal_lines
        }

        parent::tearDown();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withFailingUpdateTrigger(string $table, callable $callback): mixed
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
            "CREATE TRIGGER {$triggerName} BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$functionName}()"
        );

        try {
            return $callback();
        } finally {
            DB::connection('pgsql_admin')->statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");
            DB::connection('pgsql_admin')->statement("DROP FUNCTION IF EXISTS {$functionName}()");
        }
    }

    #[Test]
    public function a_forced_charges_update_failure_after_the_ledger_reversal_rolls_back_everything(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '400.00');

        try {
            $this->withFailingUpdateTrigger('charges', function () use ($charge) {
                app(ChargeService::class)->cancel($this->school, $charge->id);
            });
            $this->fail('Expected the injected charges UPDATE failure to propagate as a QueryException.');
        } catch (QueryException) {
            // Expected: the trigger's RAISE EXCEPTION surfaces here.
        }

        $reloaded = $context->withSchool($this->school, fn () => Charge::query()->findOrFail($charge->id));
        $this->assertNull($reloaded->cancelled_at, 'The original charge must remain active after a rolled-back cancellation.');
        $this->assertNull($reloaded->cancellation_journal_entry_id);

        $reversalCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $charge->journal_entry_id)->count(),
        );
        $this->assertSame(0, $reversalCount, 'The reversal journal entry LedgerService::reverseById() already wrote must be rolled back atomically with the failed charges UPDATE.');

        $original = $context->withSchool($this->school, fn () => JournalEntry::query()->findOrFail($charge->journal_entry_id));
        $this->assertNull($original->reversal_of_journal_entry_id, 'The original journal entry itself is never mutated.');

        $this->assertSame(
            0,
            $context->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'journal_entry.reversed')->count()),
            'A rolled-back cancellation must leave zero Finance reversal audit events.',
        );
        $this->assertSame(
            0,
            $context->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'charge.cancelled')->count()),
            'A rolled-back cancellation must leave zero charge-cancellation audit events.',
        );
        $this->assertSame(
            0,
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'journal_entry.reversed.v1')->count(),
            'A rolled-back cancellation must leave zero Finance reversal outbox events.',
        );
        $this->assertSame(
            0,
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'charge.cancelled.v1')->count(),
            'A rolled-back cancellation must leave zero charge-cancellation outbox events.',
        );
    }
}
