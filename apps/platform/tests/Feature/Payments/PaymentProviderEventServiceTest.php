<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\Exceptions\AllocationDoesNotSumToPaymentAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException;
use App\Domain\Payments\Application\Exceptions\DuplicateProviderPaymentReferenceException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Application\Exceptions\ProviderEventContentConflictException;
use App\Domain\Payments\Application\Exceptions\UnsupportedProviderEventTypeException;
use App\Domain\Payments\Application\NormalizedProviderEvent;
use App\Domain\Payments\Application\PaymentProviderEventOutcome;
use App\Domain\Payments\Application\PaymentProviderEventService;
use App\Domain\Payments\Application\RecordSettlementData;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

class PaymentProviderEventServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    #[Test]
    public function a_settled_event_creates_a_payment_allocation_and_balanced_ledger_entry(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00');

        $this->assertSame(PaymentProviderEventOutcome::Recognized, $result->outcome);

        $payment = $this->findPayment($school, $result->paymentId);
        $this->assertSame('1000.00', $payment->amount);
        $this->assertCount(1, $payment->allocations);
        $this->assertSame('1000.00', $payment->allocations->first()->amount);
        $this->assertSame($charge->id, $payment->allocations->first()->charge_id);

        $journal = $context->withSchool($school, fn () => JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id));
        $this->assertSame(2, $journal->lines->count());
        $debit = $journal->lines->firstWhere('ledger_account_id', $settlement->id);
        $credit = $journal->lines->firstWhere('ledger_account_id', $receivable->id);
        $this->assertSame('1000.00', $debit->debit_amount);
        $this->assertSame('1000.00', $credit->credit_amount);
    }

    #[Test]
    public function a_settlement_may_allocate_across_multiple_charges_on_different_receivable_accounts(): void
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

        $result = $this->recordSettlement($school, $settlement->id, [[$chargeA, '600.00'], [$chargeB, '400.00']], '1000.00');

        $payment = $this->findPayment($school, $result->paymentId);
        $this->assertCount(2, $payment->allocations);
        $this->assertSame('1000.00', $payment->amount);
    }

    #[Test]
    public function a_partial_payment_against_one_charge_leaves_a_derivable_outstanding_balance(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $this->recordSettlement($school, $settlement->id, [[$charge, '400.00']], '400.00');

        $allocated = app(TenantContext::class)->withSchool(
            $school,
            fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'),
        );
        $outstanding = Money::of($charge->amount, $charge->currency)->add(Money::of((string) $allocated, $charge->currency)->negated());
        $this->assertSame('600.00', $outstanding->amount());
    }

    #[Test]
    public function a_second_payment_may_complete_a_partially_paid_charge(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $this->recordSettlement($school, $settlement->id, [[$charge, '400.00']], '400.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '600.00']], '600.00');

        $allocated = app(TenantContext::class)->withSchool(
            $school,
            fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'),
        );
        $this->assertSame('1000.00', (string) $allocated);
    }

    #[Test]
    public function duplicate_delivery_of_the_same_event_is_a_safe_no_op(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $eventId = (string) Str::uuid();
        $reference = (string) Str::uuid();
        $occurredAt = Carbon::now();

        $first = $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00', providerEventId: $eventId, providerPaymentReference: $reference, occurredAt: $occurredAt);
        $second = $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00', providerEventId: $eventId, providerPaymentReference: $reference, occurredAt: $occurredAt);

        $this->assertSame(PaymentProviderEventOutcome::Recognized, $first->outcome);
        $this->assertSame(PaymentProviderEventOutcome::DuplicateReplay, $second->outcome);
        $this->assertSame($first->paymentId, $second->paymentId);

        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => Payment::query()->count()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => PaymentProviderEvent::query()->count()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => PaymentAllocation::query()->count()));
    }

    #[Test]
    public function conflicting_content_under_the_same_event_id_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $eventId = (string) Str::uuid();

        $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00', providerEventId: $eventId);

        $this->expectException(ProviderEventContentConflictException::class);
        $this->recordSettlement($school, $settlement->id, [[$charge, '999.00']], '999.00', providerEventId: $eventId);
    }

    #[Test]
    public function a_second_settlement_event_for_the_same_provider_payment_reference_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '2000.00');

        $reference = (string) Str::uuid();

        $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00', providerPaymentReference: $reference);

        $this->expectException(DuplicateProviderPaymentReferenceException::class);
        $this->recordSettlement($school, $settlement->id, [[$charge, '1000.00']], '1000.00', providerPaymentReference: $reference);
    }

    #[Test]
    public function an_unsupported_event_type_is_rejected(): void
    {
        $school = $this->createSchool();
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);

        $this->expectException(UnsupportedProviderEventTypeException::class);

        app(PaymentProviderEventService::class)->recordSettlement($school, new RecordSettlementData(
            event: new NormalizedProviderEvent('test-provider', (string) Str::uuid(), (string) Str::uuid(), 'payment.pending', Money::of('100.00', 'INR'), Carbon::now()),
            settlementLedgerAccountId: $settlement->id,
            allocations: [],
        ));
    }

    #[Test]
    public function zero_negative_and_non_inr_amounts_are_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        foreach (['0.00', '-100.00'] as $badAmount) {
            try {
                $this->recordSettlement($school, $settlement->id, [[$charge, $badAmount]], $badAmount);
                $this->fail("Expected InvalidSettlementDataException for amount '{$badAmount}'.");
            } catch (InvalidSettlementDataException) {
                // expected
            }
        }

        $this->expectException(InvalidSettlementDataException::class);
        app(PaymentProviderEventService::class)->recordSettlement($school, new RecordSettlementData(
            event: new NormalizedProviderEvent('test-provider', (string) Str::uuid(), (string) Str::uuid(), 'payment.settled', Money::of('100.00', 'USD'), Carbon::now()),
            settlementLedgerAccountId: $settlement->id,
            allocations: [new ChargeAllocationInput($charge->id, Money::of('100.00', 'USD'))],
        ));
    }

    #[Test]
    public function an_allocation_set_that_does_not_sum_to_the_settlement_amount_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');

        $this->expectException(AllocationDoesNotSumToPaymentAmountException::class);
        $this->recordSettlement($school, $settlement->id, [[$charge, '400.00']], '1000.00');
    }

    #[Test]
    public function an_allocation_exceeding_the_charge_amount_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');

        $this->expectException(ChargeAllocationExceedsChargeAmountException::class);
        $this->recordSettlement($school, $settlement->id, [[$charge, '600.00']], '600.00');
    }

    #[Test]
    public function allocating_against_a_cancelled_charge_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');

        app(ChargeService::class)->cancel($school, $charge->id);

        $this->expectException(ChargeIsCancelledException::class);
        $this->recordSettlement($school, $settlement->id, [[$charge, '500.00']], '500.00');
    }

    #[Test]
    public function allocating_against_a_cross_school_charge_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB, '500.00');
        $settlementA = $this->createLedgerAccount($schoolA, ['type' => 'asset']);

        $this->expectException(ChargeNotFoundException::class);
        $this->recordSettlement($schoolA, $settlementA->id, [[$chargeB, '500.00']], '500.00');
    }

    #[Test]
    public function a_cross_school_settlement_account_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $student = $this->createStudent($schoolA);
        $year = $this->createAcademicYear($schoolA);
        $receivable = $this->createLedgerAccount($schoolA, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($schoolA, ['type' => 'income']);
        $charge = $this->assessCharge($schoolA, $student, $year, $receivable, $revenue, '500.00');
        $settlementB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);

        $this->expectException(LedgerAccountNotFoundException::class);
        $this->recordSettlement($schoolA, $settlementB->id, [[$charge, '500.00']], '500.00');
    }
}
