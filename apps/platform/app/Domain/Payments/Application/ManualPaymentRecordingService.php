<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeSummary;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Application\Exceptions\InvalidManualPaymentException;
use App\Domain\Payments\Application\Exceptions\ManualPaymentIdempotencyConflictException;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Domain\PaymentSource;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment, ADR 0057 section 3):
 * the manual/offline payment ingress -- an authorized School user records
 * a payment that ALREADY happened outside Lycenza (cash, an offline bank
 * transfer, a cheque). Lycenza moves no money and contacts no bank or
 * processor.
 *
 * Unlike `PaymentProviderEventService` (a trusted SYSTEM boundary) this IS
 * an authorization boundary: every method requires
 * `finance.payments.record` in the School. It never creates a
 * `payment_provider_events` row -- the Payment it records carries manual
 * provenance (`PaymentProvenance::manual()`) and the database refuses any
 * mix of the two shapes (`payments_source_shape_check`).
 *
 * `record()` order (rule 32): capability -> input contract -> inside ONE
 * transaction: School operational (FOR SHARE, ADR 0047) -> advisory lock
 * on (School, idempotency key) -> key lookup (replay/conflict) ->
 * settlement account -> `SettledPaymentRecorder` (Charge locks, ledger,
 * Payment, allocations, audit, outbox). The unique index
 * `payments_manual_idempotency_unique` stays the authoritative claim
 * (rule 30); the advisory lock only makes two same-key requests
 * serialize so the second deterministically sees the first's Payment
 * instead of racing it into a Charge over-allocation error.
 *
 * Posted manual Payments are immutable and have no correction action in
 * v1 (owner decision 2026-09-28, ADR 0031 amendment section 2).
 */
class ManualPaymentRecordingService
{
    use AuthorizesCapability;

    public const CAPABILITY = 'finance.payments.record';

    /** Mirrors payments_manual_reference_format_check. */
    public const REFERENCE_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9 .\/_-]{0,62}[A-Za-z0-9])?$/';

    private const MAX_OPEN_CHARGES = 100;

    public function __construct(
        private readonly SettledPaymentRecorder $recorder,
        private readonly LedgerService $ledger,
        private readonly ChargeService $charges,
        private readonly SchoolOperationalGuard $guard,
        private readonly TenantContext $context,
        private readonly ChargeStateReader $states,
    ) {}

    public function record(School $school, RecordManualPaymentData $data, User $actor): ManualPaymentResult
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $occurredAt = $this->occurredAt($school, $data->occurredOn);
        $this->assertValidInput($data);
        $this->recorder->assertValidShape($data->amount, $data->allocations);

        return $this->context->withSchool($school, function () use ($school, $data, $actor, $occurredAt) {
            try {
                return DB::transaction(function () use ($school, $data, $actor, $occurredAt) {
                    $this->guard->requireOperational($school->id);

                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["payments.manual:{$school->id}:{$data->idempotencyKey}"]);

                    $existing = $this->findByKey($school, $data->idempotencyKey);
                    if ($existing !== null) {
                        return $this->replay($existing, $data, $actor, $occurredAt);
                    }

                    $this->assertSettlementAccount($school, $data->settlementLedgerAccountId);

                    $payment = $this->recorder->record($school, new SettledPaymentData(
                        amount: $data->amount,
                        settlementLedgerAccountId: $data->settlementLedgerAccountId,
                        allocations: $data->allocations,
                        settledAt: $occurredAt,
                        journalDescription: "Offline payment recorded: {$data->method->label()}",
                        provenance: PaymentProvenance::manual($data->method, $data->reference, $actor->id, $data->idempotencyKey),
                    ), 'payment.recorded_manually', [
                        'method' => $data->method->value,
                        'currency' => $data->amount->currency(),
                        'occurredOn' => $data->occurredOn,
                        'allocationCount' => count($data->allocations),
                        'hasReference' => $data->reference !== null,
                    ], $actor);

                    return new ManualPaymentResult(ManualPaymentOutcome::Recorded, $payment->id);
                });
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'payments_manual_idempotency_unique')) {
                    throw $e;
                }

                // Unreachable while the advisory lock serializes same-key
                // requests; kept because the unique index, not the lock,
                // is the authoritative guarantee (rule 30).
                $existing = $this->findByKey($school, $data->idempotencyKey)
                    ?? throw new ManualPaymentIdempotencyConflictException;

                return $this->replay($existing, $data, $actor, $occurredAt);
            }
        });
    }

    /**
     * The School's active INR asset accounts a manual payment may be
     * received into (e.g. its own "Cash" or "Bank" account).
     *
     * @return Collection<int, LedgerAccountSummary>
     */
    public function settlementAccounts(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->ledger->activeAccountsOfType($school, 'asset');
    }

    /**
     * One Student's uncancelled Charges with what is already allocated to
     * each -- the recording form's allocation candidates (display only).
     *
     * @return list<OutstandingCharge>
     */
    public function outstandingChargesForStudent(School $school, string $studentId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        if (! Str::isUuid($studentId)) {
            return [];
        }

        $charges = $this->charges->uncancelledChargesForStudent($school, $studentId, self::MAX_OPEN_CHARGES);

        // FEE.3 (ADR 0062 §15): net of live fee adjustments. E21.3A2: through
        // the carry-forward read (latest closed state + later facts).
        $states = $this->states->forCharges($school, $charges->pluck('chargeId')->all());

        return $charges->map(function (ChargeSummary $charge) use ($states) {
            $amount = Money::of($charge->amount, $charge->currency);
            $paid = $states[$charge->chargeId]->allocated ?? Money::of('0.00', $charge->currency);
            $concession = $states[$charge->chargeId]->adjusted ?? Money::of('0.00', $charge->currency);

            return new OutstandingCharge(
                chargeId: $charge->chargeId,
                description: $charge->description,
                amount: $amount->amount(),
                allocated: $paid->amount(),
                outstanding: $amount->add($paid->negated())->add($concession->negated())->amount(),
                currency: $charge->currency,
                dueDate: $charge->dueDate,
                adjusted: $concession->amount(),
            );
        })->values()->all();
    }

    private function assertValidInput(RecordManualPaymentData $data): void
    {
        if (! Str::isUuid($data->idempotencyKey)) {
            throw new InvalidManualPaymentException('idempotency_key', 'The payment form is missing its request key. Reload the form and try again.');
        }

        if ($data->reference !== null && preg_match(self::REFERENCE_PATTERN, $data->reference) !== 1) {
            throw new InvalidManualPaymentException('reference', 'The reference may contain only letters, digits, spaces and . / _ - (at most 64 characters, starting and ending with a letter or digit).');
        }
    }

    /**
     * The start of the School-local calendar day the money was received,
     * as a UTC instant. Never later than today in the School's timezone;
     * no historical lower bound (Finance has no accounting-period
     * contract). Never silently replaced with "now".
     */
    private function occurredAt(School $school, string $occurredOn): Carbon
    {
        $timezone = $school->timezone;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredOn) !== 1
            || ! checkdate((int) substr($occurredOn, 5, 2), (int) substr($occurredOn, 8, 2), (int) substr($occurredOn, 0, 4))) {
            throw new InvalidManualPaymentException('occurred_on', 'The received date must be a valid date (YYYY-MM-DD).');
        }

        if ($occurredOn > Carbon::now($timezone)->toDateString()) {
            throw new InvalidManualPaymentException('occurred_on', 'The received date cannot be in the future.');
        }

        return Carbon::createFromFormat('!Y-m-d', $occurredOn, $timezone)->utc();
    }

    private function assertSettlementAccount(School $school, string $accountId): void
    {
        $valid = Str::isUuid($accountId)
            && $this->ledger->activeAccountsOfType($school, 'asset')->contains(fn (LedgerAccountSummary $a) => $a->ledgerAccountId === $accountId);

        if (! $valid) {
            throw new InvalidManualPaymentException('settlement_ledger_account_id', 'Choose an active asset account (for example Cash or Bank) that this payment was received into.');
        }
    }

    private function findByKey(School $school, string $idempotencyKey): ?Payment
    {
        return Payment::query()
            ->where('school_id', $school->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with('allocations')
            ->first();
    }

    /**
     * Replays only the SAME request: same School (the lookup scope), same
     * recording User and identical content. Anything else fails closed.
     */
    private function replay(Payment $existing, RecordManualPaymentData $data, User $actor, Carbon $occurredAt): ManualPaymentResult
    {
        $existingAllocations = $existing->allocations
            ->mapWithKeys(fn (PaymentAllocation $a) => [$a->charge_id => Money::of($a->amount, $a->currency)->amount()])
            ->sortKeys()
            ->all();
        $requestedAllocations = collect($data->allocations)
            ->mapWithKeys(fn (ChargeAllocationInput $a) => [$a->chargeId => $a->amount->amount()])
            ->sortKeys()
            ->all();

        $matches = $existing->source === PaymentSource::Manual->value
            && $existing->recorded_by_user_id === $actor->id
            && $existing->method === $data->method->value
            && $existing->manual_reference === $data->reference
            && Money::of($existing->amount, $existing->currency)->equals($data->amount)
            && $existing->settlement_ledger_account_id === $data->settlementLedgerAccountId
            && $existing->settled_at->format('Y-m-d H:i:s') === $occurredAt->format('Y-m-d H:i:s')
            && $existingAllocations === $requestedAllocations;

        if (! $matches) {
            throw new ManualPaymentIdempotencyConflictException;
        }

        return new ManualPaymentResult(ManualPaymentOutcome::DuplicateReplay, $existing->id);
    }

    /**
     * @internal the closed method catalog, for transport-layer option lists.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function methodOptions(): array
    {
        return array_map(fn (ManualPaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()], ManualPaymentMethod::cases());
    }
}
