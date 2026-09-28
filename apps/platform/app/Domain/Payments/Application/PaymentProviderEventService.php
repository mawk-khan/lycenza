<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Application\Exceptions\DuplicateProviderPaymentReferenceException;
use App\Domain\Payments\Application\Exceptions\ProviderEventContentConflictException;
use App\Domain\Payments\Application\Exceptions\UnsupportedProviderEventTypeException;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\School;
use App\Models\User;
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
 *
 * Phase 0O.11A (ADR 0031 implementation amendment section 1): the steps
 * after the provider-event claim -- Charge locking, allocation checks,
 * ledger posting, Payment/allocation rows, audit, outbox -- moved
 * unchanged into `SettledPaymentRecorder`, which the manual/offline
 * ingress (`ManualPaymentRecordingService`) shares. This class keeps the
 * provider-specific parts: the event claim, provider-reference
 * uniqueness, and replay/conflict resolution. A manual Payment never
 * passes through here and never creates a `payment_provider_events` row.
 */
class PaymentProviderEventService
{
    private const SUPPORTED_EVENT_TYPE = 'payment.settled';

    public function __construct(
        private readonly SettledPaymentRecorder $recorder,
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

        // Phase 0O.11A: everything after the provider-event claim is the
        // shared settled-payment core, unchanged from 0G.5 (ADR 0031
        // implementation amendment section 1).
        $payment = $this->recorder->record($school, new SettledPaymentData(
            amount: $event->amount,
            settlementLedgerAccountId: $data->settlementLedgerAccountId,
            allocations: $data->allocations,
            settledAt: $event->occurredAt,
            journalDescription: "Payment settlement: {$event->provider}/{$event->providerPaymentReference}",
            provenance: PaymentProvenance::provider($event->provider, $event->providerPaymentReference, $providerEvent->id),
        ), 'payment.settled', [
            'currency' => $event->amount->currency(),
            'allocationCount' => count($data->allocations),
        ], $actor);

        return new PaymentProviderEventResult(PaymentProviderEventOutcome::Recognized, $payment->id, $payment->journal_entry_id);
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
        $this->recorder->assertValidShape($data->event->amount, $data->allocations);
    }

    private function violatesConstraint(UniqueConstraintViolationException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
