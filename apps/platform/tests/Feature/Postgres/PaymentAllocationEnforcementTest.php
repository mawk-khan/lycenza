<?php

namespace Tests\Feature\Postgres;

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 correction (sections 11, 12, 18, 19, ADR 0031): proves the
 * `payments_fully_allocated_check` DEFERRABLE INITIALLY DEFERRED
 * constraint trigger, and the `payments_provider_event_unique`/
 * `payments_journal_entry_unique` structural uniqueness constraints,
 * are database-authoritative -- constructing rows directly via raw
 * Eloquent creates (never through `PaymentProviderEventService`, whose
 * own Application-layer validation would otherwise mask a broken
 * database invariant). Mirrors
 * `Tests\Feature\Finance\JournalEntryBalanceEnforcementTest`'s exact
 * `forceConstraintCheck()` mechanism -- runs entirely inside the
 * ordinary DatabaseTransactions-wrapped test transaction (rolled back
 * automatically), `SET CONSTRAINTS ALL IMMEDIATE` simply forces the
 * DEFERRED trigger to evaluate NOW instead of silently never running at
 * all under a transaction this suite never lets commit for real.
 */
class PaymentAllocationEnforcementTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function forceConstraintCheck(School $school): void
    {
        app(TenantContext::class)->withSchool(
            $school,
            fn () => DB::connection('pgsql')->statement('SET CONSTRAINTS ALL IMMEDIATE'),
        );
    }

    /**
     * @return array{0: PaymentProviderEvent, 1: object}
     */
    private function createChargeAndSettlementFixtures(School $school, string $chargeAmount = '1000.00')
    {
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, $chargeAmount);

        return [$settlement, $charge];
    }

    #[Test]
    public function an_under_allocated_payment_is_rejected_at_commit(): void
    {
        $school = $this->createSchool();
        [$settlement, $charge] = $this->createChargeAndSettlementFixtures($school);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        app(TenantContext::class)->withSchool($school, function () use ($school, $settlement, $revenue, $charge) {
            $journal = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');
            $event = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => (string) Str::uuid(), 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);
            $payment = Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => $event->provider_payment_reference, 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journal->id, 'provider_event_id' => $event->id, 'settled_at' => now(),
            ]);
            PaymentAllocation::query()->create([
                'school_id' => $school->id, 'payment_id' => $payment->id,
                'charge_id' => $charge->id, 'amount' => '600.00', 'currency' => 'INR',
            ]);
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    #[Test]
    public function an_over_allocated_payment_relative_to_its_own_amount_is_rejected_at_commit(): void
    {
        $school = $this->createSchool();
        [$settlement, $charge] = $this->createChargeAndSettlementFixtures($school, '2000.00');
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        app(TenantContext::class)->withSchool($school, function () use ($school, $settlement, $revenue, $charge) {
            $journal = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');
            $event = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => (string) Str::uuid(), 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);
            $payment = Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => $event->provider_payment_reference, 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journal->id, 'provider_event_id' => $event->id, 'settled_at' => now(),
            ]);
            PaymentAllocation::query()->create([
                'school_id' => $school->id, 'payment_id' => $payment->id,
                'charge_id' => $charge->id, 'amount' => '1100.00', 'currency' => 'INR',
            ]);
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    #[Test]
    public function a_fully_and_exactly_allocated_payment_across_two_charges_passes_the_deferred_check(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivableA = $this->createLedgerAccount($school, ['type' => 'asset']);
        $receivableB = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $chargeA = $this->assessCharge($school, $student, $year, $receivableA, $revenue, '600.00');
        $chargeB = $this->assessCharge($school, $student, $year, $receivableB, $revenue, '400.00');

        app(TenantContext::class)->withSchool($school, function () use ($school, $settlement, $revenue, $chargeA, $chargeB) {
            $journal = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');
            $event = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => (string) Str::uuid(), 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);
            $payment = Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => $event->provider_payment_reference, 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journal->id, 'provider_event_id' => $event->id, 'settled_at' => now(),
            ]);
            PaymentAllocation::query()->create(['school_id' => $school->id, 'payment_id' => $payment->id, 'charge_id' => $chargeA->id, 'amount' => '600.00', 'currency' => 'INR']);
            PaymentAllocation::query()->create(['school_id' => $school->id, 'payment_id' => $payment->id, 'charge_id' => $chargeB->id, 'amount' => '400.00', 'currency' => 'INR']);
        });

        $this->forceConstraintCheck($school);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function two_payments_referencing_the_same_provider_event_are_rejected(): void
    {
        $school = $this->createSchool();
        [$settlement, $charge] = $this->createChargeAndSettlementFixtures($school);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(UniqueConstraintViolationException::class);

        app(TenantContext::class)->withSchool($school, function () use ($school, $settlement, $revenue) {
            $event = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => (string) Str::uuid(), 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);

            $journalA = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');
            Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => 'ref-a', 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journalA->id, 'provider_event_id' => $event->id, 'settled_at' => now(),
            ]);

            $journalB = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');
            Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => 'ref-b', 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journalB->id, 'provider_event_id' => $event->id, 'settled_at' => now(),
            ]);
        });
    }

    #[Test]
    public function two_payments_referencing_the_same_journal_entry_are_rejected(): void
    {
        $school = $this->createSchool();
        [$settlement, $charge] = $this->createChargeAndSettlementFixtures($school);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(UniqueConstraintViolationException::class);

        app(TenantContext::class)->withSchool($school, function () use ($school, $settlement, $revenue) {
            $journal = $this->postBalancedJournalEntry($school, $settlement, $revenue, '1000.00');

            $eventA = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => 'ref-a', 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);
            Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => 'ref-a', 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journal->id, 'provider_event_id' => $eventA->id, 'settled_at' => now(),
            ]);

            $eventB = PaymentProviderEvent::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.settled',
                'provider_payment_reference' => 'ref-b', 'amount' => '1000.00',
                'currency' => 'INR', 'occurred_at' => now(), 'received_at' => now(),
            ]);
            Payment::query()->create([
                'school_id' => $school->id, 'provider' => 'test-provider',
                'provider_payment_reference' => 'ref-b', 'amount' => '1000.00',
                'currency' => 'INR', 'settlement_ledger_account_id' => $settlement->id,
                'journal_entry_id' => $journal->id, 'provider_event_id' => $eventB->id, 'settled_at' => now(),
            ]);
        });
    }
}
