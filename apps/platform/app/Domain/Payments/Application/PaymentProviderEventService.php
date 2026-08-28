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
use App\Domain\Payments\Application\Exceptions\DuplicateProviderPaymentReferenceException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Application\Exceptions\ProviderEventContentConflictException;
use App\Domain\Payments\Application\Exceptions\UnsupportedProviderEventTypeException;
use App\Domain\Payments\Events\PaymentSettled;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0G.5: the only sanctioned write path for `payment_provider_events`/
 * `payments`/`payment_allocations` -- the trusted core a future provider/
 * HTTP adapter (0G.6+, not implemented here) will call once it has
 * independently verified a real provider's signature and produced a
 * `NormalizedProviderEvent` (rule 18: authenticity verification is
 * DEFERRED to that adapter; this class trusts its caller completely,
 * exactly like `LedgerService::post()`/`ChargeService::assess()` trust
 * theirs). NOT an authorization boundary -- provider ingestion is a
 * trusted SYSTEM boundary, never a human capability (rule 54): no
 * `Gate::authorize()` call exists anywhere in this class, and `$actor`
 * (when given at all -- e.g. a test/administrative bootstrap driving
 * this directly) is passed through purely for audit provenance.
 *
 * Four concepts, kept structurally distinct (this checkpoint's
 * objective): `PaymentProviderEvent` (external ingress fact),
 * `Payment` (the School's normalized settlement fact), `PaymentAllocation`
 * (payment-to-charge assignment), and the `JournalEntry` `LedgerService::post()`
 * creates (the accounting effect) -- never collapsed into one table or
 * mutable status object.
 *
 * Idempotency: a plain INSERT into `payment_provider_events` relying on
 * `payment_provider_events_event_unique`
 * (`school_id, provider, provider_event_id`), caught via
 * `UniqueConstraintViolationException` -- the same claim-via-unique-
 * constraint pattern `App\Support\Idempotency\IdempotencyGuard::claim()`
 * uses, applied to a dedicated table (FINANCE.md "Payment-callback
 * idempotency"). The ENTIRE business effect (event + Payment +
 * allocations + ledger posting + audit + outbox) commits inside ONE
 * `DB::transaction()` -- a mid-transaction failure at ANY step rolls
 * back the provider-event claim too, so the SAME `provider_event_id`
 * remains genuinely retryable after a failed first attempt (rule 42),
 * with no separate release/recovery mechanism needed.
 *
 * Allocation model (0G.5 resolution of the "overpayment/unallocated
 * money" pre-code gate, recorded here and in
 * `create_payment_allocations_table`'s migration docblock): the caller-
 * supplied allocation set MUST sum to exactly the settlement amount
 * (`AllocationDoesNotSumToPaymentAmountException` otherwise) and must
 * not push any named Charge's cumulative allocations past its own
 * amount (`ChargeAllocationExceedsChargeAmountException`) -- true
 * overpayment/unapplied-cash accounting is explicitly deferred, never
 * silently invented.
 *
 * Locking/deadlock avoidance (rule 33): every Charge named by the
 * allocation set is locked via
 * `ChargeService::lockChargeForAllocation()` in ascending `chargeId`
 * order, exactly once each, before any ledger/Payment/allocation write
 * begins -- this gives a clean, deterministic Application-layer error
 * (`ChargeIsCancelledException`/`ChargeAllocationExceedsChargeAmountException`)
 * in the ordinary (non-racing) case. The AUTHORITATIVE concurrency/
 * cancellation-interlock guarantee (ADR 0031) is the database's own
 * `payment_allocations_lock_and_validate_charge_trigger` (`create_payment_allocations_table`
 * migration) -- it takes the SAME `SELECT ... FOR UPDATE` lock again
 * (a harmless no-op re-acquisition within this same transaction) and
 * would independently reject an over-allocation or a cancelled-Charge
 * allocation even for a caller that skipped this class entirely. This
 * is what makes a concurrent allocate-vs-cancel race on the SAME
 * Charge (rule 48) and a concurrent over-allocation race (rule 31/63)
 * resolve to one coherent outcome rather than corrupt state,
 * independent of Application-layer discipline.
 */
class PaymentProviderEventService
{
    private const SUPPORTED_EVENT_TYPE = 'payment.settled';

    private const SUPPORTED_CURRENCY = 'INR';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function recordSettlement(School $school, RecordSettlementData $data, ?User $actor = null): PaymentProviderEventResult
    {
        $event = $data->event;

        if ($event->eventType !== self::SUPPORTED_EVENT_TYPE) {
            throw new UnsupportedProviderEventTypeException($event->eventType);
        }

        $this->assertValidSettlementShape($data);

        return $this->context->withSchool($school, function () use ($school, $data, $event, $actor) {
            try {
                return DB::transaction(fn () => $this->processSettlement($school, $data, $event, $actor));
            } catch (UniqueConstraintViolationException $e) {
                if ($this->violatesConstraint($e, 'payment_provider_events_event_unique')) {
                    return $this->resolveExistingEvent($school, $event);
                }

                if ($this->violatesConstraint($e, 'payments_provider_reference_unique')) {
                    throw new DuplicateProviderPaymentReferenceException($event->provider, $event->providerPaymentReference);
                }

                throw $e;
            }
        });
    }

    private function processSettlement(School $school, RecordSettlementData $data, NormalizedProviderEvent $event, ?User $actor): PaymentProviderEventResult
    {
        $providerEvent = PaymentProviderEvent::query()->create([
            'school_id' => $school->id,
            'provider' => $event->provider,
            'provider_event_id' => $event->providerEventId,
            'event_type' => $event->eventType,
            'provider_payment_reference' => $event->providerPaymentReference,
            'amount' => $event->amount->amount(),
            'currency' => $event->amount->currency(),
            'occurred_at' => $event->occurredAt,
        ]);

        // Ascending order (rule 33): deterministic lock order across
        // every Charge this settlement touches, avoiding deadlock
        // against a concurrent operation locking the same Charges in
        // any order (this is the only place in this checkpoint that
        // locks more than one Charge at a time).
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
            $alreadyAllocated = $this->sumExistingAllocations($allocation->chargeId, $event->amount->currency());
            $projected = $alreadyAllocated->add($allocation->amount);
            $remaining = $snapshot->amount->add($projected->negated());

            if ($remaining->isNegative()) {
                throw new ChargeAllocationExceedsChargeAmountException($allocation->chargeId, $projected->amount(), $snapshot->amount->amount());
            }
        }

        $lines = [new JournalLineData($data->settlementLedgerAccountId, JournalSide::Debit, $event->amount)];
        foreach ($data->allocations as $allocation) {
            $lines[] = new JournalLineData($snapshots[$allocation->chargeId]->receivableLedgerAccountId, JournalSide::Credit, $allocation->amount);
        }

        $posted = $this->ledger->post($school, new PostJournalEntryData(
            currency: $event->amount->currency(),
            description: "Payment settlement: {$event->provider}/{$event->providerPaymentReference}",
            lines: $lines,
        ), $actor);

        $payment = Payment::query()->create([
            'school_id' => $school->id,
            'provider' => $event->provider,
            'provider_payment_reference' => $event->providerPaymentReference,
            'amount' => $event->amount->amount(),
            'currency' => $event->amount->currency(),
            'settlement_ledger_account_id' => $data->settlementLedgerAccountId,
            'journal_entry_id' => $posted->journalEntryId,
            'provider_event_id' => $providerEvent->id,
            'settled_at' => $event->occurredAt,
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

        $this->audit->school($school, 'payment.settled', actor: $actor, subject: $payment, metadata: [
            'currency' => $payment->currency,
            'allocationCount' => count($data->allocations),
        ]);

        event(new PaymentSettled($school->id, $payment->id, $posted->journalEntryId, $payment->currency, count($data->allocations)));

        return new PaymentProviderEventResult(PaymentProviderEventOutcome::Recognized, $payment->id, $posted->journalEntryId);
    }

    private function sumExistingAllocations(string $chargeId, string $currency): Money
    {
        $sum = PaymentAllocation::query()->where('charge_id', $chargeId)->sum('amount');

        return Money::of((string) $sum, $currency);
    }

    private function resolveExistingEvent(School $school, NormalizedProviderEvent $event): PaymentProviderEventResult
    {
        return $this->context->withSchool($school, function () use ($school, $event) {
            $existing = PaymentProviderEvent::query()
                ->where('school_id', $school->id)
                ->where('provider', $event->provider)
                ->where('provider_event_id', $event->providerEventId)
                ->first();

            if ($existing === null) {
                // The conflicting transaction committed (that is the
                // only way UniqueConstraintViolationException surfaces
                // here), so its row must be visible now; this branch is
                // unreachable under the append-only guarantee
                // (TenantRls::makeAppendOnly() -- no delete path exists).
                throw new ProviderEventContentConflictException($event->provider, $event->providerEventId);
            }

            $matches = $existing->event_type === $event->eventType
                && $existing->provider_payment_reference === $event->providerPaymentReference
                && $existing->currency === $event->amount->currency()
                && Money::of($existing->amount, $existing->currency)->equals($event->amount)
                // Second-precision comparison: `occurred_at` is stored via
                // Eloquent's default `Y-m-d H:i:s` DB serialization format,
                // which truncates sub-second precision on write -- comparing
                // against the caller's still-in-memory, un-truncated
                // `$event->occurredAt` with Carbon::equalTo() (which compares
                // the full microsecond instant) would spuriously report a
                // "conflict" for a genuinely identical redelivery.
                && $existing->occurred_at->format('Y-m-d H:i:s') === $event->occurredAt->format('Y-m-d H:i:s');

            if (! $matches) {
                throw new ProviderEventContentConflictException($event->provider, $event->providerEventId);
            }

            $payment = Payment::query()
                ->where('school_id', $school->id)
                ->where('provider_event_id', $existing->id)
                ->firstOrFail();

            return new PaymentProviderEventResult(PaymentProviderEventOutcome::DuplicateReplay, $payment->id, $payment->journal_entry_id);
        });
    }

    private function assertValidSettlementShape(RecordSettlementData $data): void
    {
        $event = $data->event;

        if (! $event->amount->isPositive()) {
            throw new InvalidSettlementDataException("Settlement amount must be strictly positive, got '{$event->amount->amount()}'.");
        }

        if ($event->amount->currency() !== self::SUPPORTED_CURRENCY) {
            throw new InvalidSettlementDataException("Unsupported currency '{$event->amount->currency()}': Phase 0G supports only ".self::SUPPORTED_CURRENCY.'.');
        }

        if (count($data->allocations) === 0) {
            throw new InvalidSettlementDataException('At least one charge allocation is required to record a settlement.');
        }

        $chargeIds = collect($data->allocations)->pluck('chargeId');
        if ($chargeIds->unique()->count() !== $chargeIds->count()) {
            throw new InvalidSettlementDataException('A settlement may not allocate to the same charge more than once in a single operation.');
        }

        $total = Money::of('0', $event->amount->currency());
        foreach ($data->allocations as $allocation) {
            if (! $allocation->amount->isPositive()) {
                throw new InvalidSettlementDataException("Allocation amount must be strictly positive, got '{$allocation->amount->amount()}'.");
            }

            if ($allocation->amount->currency() !== $event->amount->currency()) {
                throw new InvalidSettlementDataException(
                    "Allocation currency '{$allocation->amount->currency()}' does not match the settlement's currency '{$event->amount->currency()}'."
                );
            }

            $total = $total->add($allocation->amount);
        }

        if (! $total->equals($event->amount)) {
            throw new AllocationDoesNotSumToPaymentAmountException($total->amount(), $event->amount->amount());
        }
    }

    private function violatesConstraint(UniqueConstraintViolationException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
