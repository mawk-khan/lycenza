<?php

namespace Tests\Feature\Payments;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Application\PaymentProviderEventOutcome;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
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
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 (rule 40/43/44/45): proves
 * `PaymentProviderEventService::recordSettlement()`'s atomicity with a
 * REAL injected failure at the LAST write step (the `payment_allocations`
 * insert), AFTER the provider event, the settlement journal posting, and
 * the `payments` row have all already been written earlier in the SAME
 * transaction. Mirrors
 * `Tests\Feature\Finance\LedgerServiceAtomicityTest`/
 * `Tests\Feature\Fees\ChargeCancellationAtomicityTest`'s exact
 * `withFailingTrigger()` mechanism and $connectionsToTransact = []
 * rationale (CREATE TRIGGER via `pgsql_admin` would self-deadlock
 * against this test's own open `pgsql` transaction otherwise).
 */
class PaymentAtomicityTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            // domain_event_outbox has no FK/cascade to schools (it is a
            // platform-level table, ADR 0025) -- a non-transactional
            // test's real, committed outbox rows would otherwise persist
            // for the remainder of the suite run and compete with later
            // tests' own rows for App\Console\Commands\DispatchOutboxEvents'
            // fixed --batch window.
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->school->delete();
        }

        parent::tearDown();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withFailingInsertTrigger(string $table, callable $callback): mixed
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

    #[Test]
    public function a_forced_allocation_insert_failure_rolls_back_the_provider_event_payment_and_ledger_posting_too(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        try {
            $this->withFailingInsertTrigger('payment_allocations', function () use ($settlement, $charge) {
                $this->recordSettlement($this->school, $settlement->id, [[$charge, '500.00']], '500.00');
            });
            $this->fail('Expected the injected payment_allocations insert failure to propagate.');
        } catch (QueryException) {
            // Expected
        }

        $this->assertSame(0, $context->withSchool($this->school, fn () => Payment::query()->count()), 'A rolled-back settlement must leave zero Payment rows.');
        $this->assertSame(0, $context->withSchool($this->school, fn () => PaymentAllocation::query()->count()), 'A rolled-back settlement must leave zero PaymentAllocation rows.');
        $this->assertSame(0, $context->withSchool($this->school, fn () => PaymentProviderEvent::query()->count()), 'A rolled-back settlement must leave zero PaymentProviderEvent rows -- the provider event id remains retryable.');

        $journalCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('id', '!=', $charge->journal_entry_id)->count(),
        );
        $this->assertSame(0, $journalCount, 'A rolled-back settlement must leave zero NEW journal entries (only the original charge recognition journal remains).');

        $this->assertSame(
            0,
            $context->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.settled')->count()),
            'A rolled-back settlement must leave zero payment.settled audit events.',
        );
        $this->assertSame(
            0,
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'payment.settled.v1')->count(),
            'A rolled-back settlement must leave zero payment.settled outbox events.',
        );
    }

    /**
     * Phase 0G.5 closure correction (section 26): the companion half of
     * the atomicity proof above -- not merely that a failed first
     * attempt leaves zero footprint, but that the SAME `provider_event_id`
     * genuinely remains usable afterward and a retry succeeds exactly
     * once. This is what makes rule 42's "no separate release/recovery
     * mechanism needed" true in practice, not just in the rollback-count
     * assertions.
     */
    #[Test]
    public function a_retry_with_the_same_provider_event_id_after_a_failed_first_attempt_succeeds_once(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        $providerEventId = (string) Str::uuid();
        $providerPaymentReference = (string) Str::uuid();

        try {
            $this->withFailingInsertTrigger('payment_allocations', function () use ($settlement, $charge, $providerEventId, $providerPaymentReference) {
                $this->recordSettlement(
                    $this->school, $settlement->id, [[$charge, '500.00']], '500.00',
                    providerEventId: $providerEventId, providerPaymentReference: $providerPaymentReference,
                );
            });
            $this->fail('Expected the injected payment_allocations insert failure to propagate.');
        } catch (QueryException) {
            // Expected -- first attempt fails with zero durable footprint
            // (proven by the preceding test); the trigger is removed by
            // withFailingInsertTrigger()'s own finally block before this
            // catch block returns.
        }

        $this->assertSame(0, $context->withSchool($this->school, fn () => PaymentProviderEvent::query()->count()), 'The failed first attempt must leave no durable provider-event claim.');

        // Retry: same provider_event_id, same provider_payment_reference,
        // no failing trigger installed this time.
        $result = $this->recordSettlement(
            $this->school, $settlement->id, [[$charge, '500.00']], '500.00',
            providerEventId: $providerEventId, providerPaymentReference: $providerPaymentReference,
        );

        $this->assertSame(PaymentProviderEventOutcome::Recognized, $result->outcome, 'The retry must succeed as a fresh recognition, not a replay of a nonexistent prior success.');
        $this->assertSame(1, $context->withSchool($this->school, fn () => Payment::query()->count()), 'Exactly one Payment must exist after the retry succeeds.');
        $this->assertSame(1, $context->withSchool($this->school, fn () => PaymentProviderEvent::query()->count()), 'Exactly one PaymentProviderEvent must exist after the retry succeeds.');
        $this->assertSame(
            1,
            $context->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.settled')->count()),
            'The successful retry must produce exactly one payment.settled audit event.',
        );
        $this->assertSame(
            1,
            DomainEventOutbox::query()->where('school_id', $this->school->id)->where('event_type', 'payment.settled.v1')->count(),
            'The successful retry must produce exactly one payment.settled outbox event.',
        );
    }
}
