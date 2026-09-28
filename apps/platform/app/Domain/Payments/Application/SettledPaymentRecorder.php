<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeAllocationSnapshot;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Payments\Application\Exceptions\AllocationDoesNotSumToPaymentAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Events\PaymentSettled;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 1): the ONE
 * internal core that records an already-settled payment -- extracted
 * unchanged from 0G.5's `PaymentProviderEventService::processSettlement()`
 * so the verified provider ingress and the authorized manual ingress
 * (`ManualPaymentRecordingService`) share every Payment/allocation/ledger
 * invariant instead of duplicating it.
 *
 * NOT an authorization or authenticity boundary: it never checks a
 * capability, never verifies a provider and never knows how its caller
 * established authority. It must run inside the caller's
 * `DB::transaction()` with the School's TenantContext already set, so the
 * caller's own claim (a provider-event row, a manual idempotency key)
 * commits or rolls back with the Payment, allocations, ledger posting,
 * audit row and outbox event as one unit.
 *
 * Locking/deadlock avoidance (rule 33), unchanged from 0G.5: every Charge
 * named by the allocation set is locked via
 * `ChargeService::lockChargeForAllocation()` in ascending id order, once
 * each, before any write -- the clean Application-layer error for the
 * ordinary case. The database's `payment_allocations` triggers remain
 * the authoritative over-allocation/cancellation/freeze guarantee for
 * every caller.
 */
class SettledPaymentRecorder
{
    public const SUPPORTED_CURRENCY = 'INR';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Pure shape validation, no database access -- callers run it before
     * opening their transaction (preserving 0G.5's error precedence), and
     * `record()` runs it again as its own precondition.
     *
     * @param  list<ChargeAllocationInput>  $allocations
     */
    public function assertValidShape(Money $amount, array $allocations): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidSettlementDataException("Settlement amount must be strictly positive, got '{$amount->amount()}'.");
        }

        if ($amount->currency() !== self::SUPPORTED_CURRENCY) {
            throw new InvalidSettlementDataException("Unsupported currency '{$amount->currency()}': Phase 0G supports only ".self::SUPPORTED_CURRENCY.'.');
        }

        if (count($allocations) === 0) {
            throw new InvalidSettlementDataException('At least one charge allocation is required to record a settlement.');
        }

        $chargeIds = collect($allocations)->pluck('chargeId');
        if ($chargeIds->unique()->count() !== $chargeIds->count()) {
            throw new InvalidSettlementDataException('A settlement may not allocate to the same charge more than once in a single operation.');
        }

        $total = Money::of('0', $amount->currency());
        foreach ($allocations as $allocation) {
            if (! $allocation->amount->isPositive()) {
                throw new InvalidSettlementDataException("Allocation amount must be strictly positive, got '{$allocation->amount->amount()}'.");
            }

            if ($allocation->amount->currency() !== $amount->currency()) {
                throw new InvalidSettlementDataException(
                    "Allocation currency '{$allocation->amount->currency()}' does not match the settlement's currency '{$amount->currency()}'."
                );
            }

            $total = $total->add($allocation->amount);
        }

        if (! $total->equals($amount)) {
            throw new AllocationDoesNotSumToPaymentAmountException($total->amount(), $amount->amount());
        }
    }

    /**
     * @param  array<string, mixed>  $auditMetadata
     */
    public function record(School $school, SettledPaymentData $data, string $auditAction, array $auditMetadata, ?User $actor = null): Payment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SettledPaymentRecorder::record() must run inside the caller\'s database transaction.');
        }

        $this->assertValidShape($data->amount, $data->allocations);

        // Ascending order (rule 33): deterministic lock order across
        // every Charge this settlement touches, avoiding deadlock
        // against a concurrent operation locking the same Charges in
        // any order.
        $chargeIds = collect($data->allocations)->pluck('chargeId')->unique()->sort()->values();

        /** @var array<string, ChargeAllocationSnapshot> $snapshots */
        $snapshots = [];
        foreach ($chargeIds as $chargeId) {
            $snapshot = $this->charges->lockChargeForAllocation($school, $chargeId);

            if ($snapshot->isCancelled) {
                throw new ChargeIsCancelledException($chargeId);
            }

            $snapshots[$chargeId] = $snapshot;
        }

        foreach ($data->allocations as $allocation) {
            $snapshot = $snapshots[$allocation->chargeId];
            $alreadyAllocated = $this->sumExistingAllocations($allocation->chargeId, $data->amount->currency());
            $projected = $alreadyAllocated->add($allocation->amount);
            $remaining = $snapshot->amount->add($projected->negated());

            if ($remaining->isNegative()) {
                throw new ChargeAllocationExceedsChargeAmountException($allocation->chargeId, $projected->amount(), $snapshot->amount->amount());
            }
        }

        $lines = [new JournalLineData($data->settlementLedgerAccountId, JournalSide::Debit, $data->amount)];
        foreach ($data->allocations as $allocation) {
            $lines[] = new JournalLineData($snapshots[$allocation->chargeId]->receivableLedgerAccountId, JournalSide::Credit, $allocation->amount);
        }

        $posted = $this->ledger->post($school, new PostJournalEntryData(
            currency: $data->amount->currency(),
            description: $data->journalDescription,
            lines: $lines,
        ), $actor);

        $payment = Payment::query()->create([
            'school_id' => $school->id,
            ...$data->provenance->columns(),
            'amount' => $data->amount->amount(),
            'currency' => $data->amount->currency(),
            'settlement_ledger_account_id' => $data->settlementLedgerAccountId,
            'journal_entry_id' => $posted->journalEntryId,
            'settled_at' => $data->settledAt,
        ]);

        foreach ($data->allocations as $allocation) {
            PaymentAllocation::query()->create([
                'school_id' => $school->id,
                'payment_id' => $payment->id,
                'charge_id' => $allocation->chargeId,
                'amount' => $allocation->amount->amount(),
                'currency' => $allocation->amount->currency(),
            ]);
        }

        $this->audit->school($school, $auditAction, actor: $actor, subject: $payment, metadata: $auditMetadata);

        event(new PaymentSettled($school->id, $payment->id, $posted->journalEntryId, $payment->currency, count($data->allocations)));

        return $payment;
    }

    private function sumExistingAllocations(string $chargeId, string $currency): Money
    {
        $sum = PaymentAllocation::query()->where('charge_id', $chargeId)->sum('amount');

        return Money::of((string) $sum, $currency);
    }
}
