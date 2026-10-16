<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeAllocationSnapshot;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionIdempotencyConflictException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * UAT-C3 (ADR 0031 amendment §3, ADR 0075 §16): the only way to correct a
 * posted Payment. Posted Payments, allocations and receipts never change; a
 * correction is a new, linked record with its own journal entry:
 *
 * - **reallocation** -- the money is valid but (part of it) sits on the
 *   wrong charge: move an amount from one charge to another uncancelled
 *   charge of the same Student. Cash received is unchanged. Ledger: debit
 *   the source charge's receivable, credit the destination's.
 * - **reversal** -- the Payment should not stand as recorded (wrong amount,
 *   entered twice, wrong Student): take everything it still has off its
 *   charges back to its own settlement account. At most once; nothing
 *   corrects a reversed Payment afterwards. Record a replacement as an
 *   ordinary new payment. Ledger: debit each charge's receivable, credit
 *   the settlement account. The receipt stays, marked reversed.
 * - **refund** -- money actually paid back to the payer: an amount of what
 *   this Payment has on one charge, out of an active asset account, by
 *   cash, bank transfer or cheque, on a date. The charge is owed again (it
 *   can then be cancelled or concession-reviewed through the normal paths).
 *   Ledger: debit the charge's receivable, credit the account paid from.
 *
 * Rules for every correction:
 * - Capabilities: reallocation `finance.payments.record` +
 *   `finance.charges.manage`; reversal and refund `finance.ledger.reverse`
 *   + `finance.payments.view` (School Admin). Fresh MFA is required by the
 *   web controller, as for recording a payment (rule 95).
 * - A reason (5-500 characters) is stored on the correction row.
 * - Order (rule 32): capability -> input -> one transaction: School
 *   operational -> advisory lock on (School, idempotency key) -> key lookup
 *   (same User + same request replays; anything else fails closed) ->
 *   advisory lock on the Payment (every correction of one Payment
 *   serializes) -> charge row locks in ascending id (the lock allocation,
 *   cancellation and concessions take) -> checks on the locked state ->
 *   ledger -> correction + lines -> audit. The `payment_corrections` /
 *   `payment_correction_lines` triggers remain the authoritative guarantee
 *   (balanced lines, capacity, no over-release, nothing after a reversal).
 * - Concessions are never a correction tool (they stay a maker/checker
 *   reduction of what is owed).
 */
class PaymentCorrectionService
{
    use AuthorizesCapability;

    public const array REALLOCATE_CAPABILITIES = ['finance.payments.record', 'finance.charges.manage'];

    public const array REVERSE_REFUND_CAPABILITIES = ['finance.ledger.reverse', 'finance.payments.view'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly PaymentApplicationReader $applications,
        private readonly SchoolOperationalGuard $guard,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return list<string> the capabilities a correction kind needs */
    public static function capabilitiesFor(string $kind): array
    {
        return match ($kind) {
            PaymentCorrection::REALLOCATION => self::REALLOCATE_CAPABILITIES,
            PaymentCorrection::REVERSAL, PaymentCorrection::REFUND => self::REVERSE_REFUND_CAPABILITIES,
            default => throw new InvalidArgumentException("Unknown payment correction '{$kind}'."),
        };
    }

    /** @return array{correctionId: string, replayed: bool} */
    public function correct(School $school, PaymentCorrectionData $data, User $actor): array
    {
        foreach (self::capabilitiesFor($data->kind) as $capability) {
            $this->authorizeCapabilityFor($actor, $capability, $school);
        }
        $reason = trim($data->reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw PaymentCorrectionRefusedException::reasonRequired();
        }
        if ($data->kind !== PaymentCorrection::REVERSAL && ($data->amount === null || ! $data->amount->isPositive() || ! preg_match('/^\d{1,12}\.\d{2}$/', $data->amount->amount()))) {
            throw PaymentCorrectionRefusedException::invalidAmount();
        }
        if ($data->kind === PaymentCorrection::REFUND && $data->method === null) {
            throw PaymentCorrectionRefusedException::invalidMethod();
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $data, $actor, $reason) {
            $this->guard->requireOperational($school->id);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["payments.correction:{$school->id}:{$data->idempotencyKey}"]);

            $existing = PaymentCorrection::query()->where('school_id', $school->id)->where('idempotency_key', $data->idempotencyKey)->with('lines')->first();
            if ($existing !== null) {
                return $this->replay($existing, $data, $actor, $reason);
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["payments.correct-payment:{$school->id}:{$data->paymentId}"]);
            $payment = Payment::query()->where('school_id', $school->id)->find($data->paymentId)
                ?? throw new PaymentNotFoundException($data->paymentId);
            if (PaymentCorrection::query()->where('payment_id', $payment->id)->where('kind', PaymentCorrection::REVERSAL)->exists()) {
                throw PaymentCorrectionRefusedException::alreadyReversed();
            }

            $current = $this->applications->forPayment($school, $payment->id);
            $snapshots = $this->lockCharges($school, array_values(array_unique(array_filter([...array_keys($current), $data->chargeId, $data->toChargeId]))));

            [$lines, $journal, $amount, $account] = match ($data->kind) {
                PaymentCorrection::REALLOCATION => $this->planReallocation($school, $data, $current, $snapshots),
                PaymentCorrection::REVERSAL => $this->planReversal($payment, $current, $snapshots),
                PaymentCorrection::REFUND => $this->planRefund($school, $payment, $data, $current, $snapshots),
                default => throw new InvalidArgumentException("Unknown payment correction '{$data->kind}'."),
            };

            $posted = $this->ledger->post($school, new PostJournalEntryData(
                currency: $payment->currency,
                description: match ($data->kind) {
                    PaymentCorrection::REALLOCATION => 'Payment reallocated between charges',
                    PaymentCorrection::REVERSAL => 'Payment reversed',
                    default => 'Payment refunded',
                },
                lines: $journal,
            ), $actor);

            $correction = PaymentCorrection::query()->create([
                'school_id' => $school->id,
                'payment_id' => $payment->id,
                'kind' => $data->kind,
                'amount' => $amount->amount(),
                'currency' => $payment->currency,
                'journal_entry_id' => $posted->journalEntryId,
                'settlement_ledger_account_id' => $account,
                'refund_method' => $data->kind === PaymentCorrection::REFUND ? $data->method?->value : null,
                'refund_reference' => $data->kind === PaymentCorrection::REFUND ? $this->reference($data->reference) : null,
                'refunded_on' => $data->kind === PaymentCorrection::REFUND ? $data->refundedOn : null,
                'reason' => $reason,
                'corrected_by_user_id' => $actor->id,
                'idempotency_key' => $data->idempotencyKey,
            ]);
            foreach ($lines as [$chargeId, $direction, $lineAmount]) {
                PaymentCorrectionLine::query()->create([
                    'school_id' => $school->id,
                    'payment_correction_id' => $correction->id,
                    'payment_id' => $payment->id,
                    'charge_id' => $chargeId,
                    'direction' => $direction,
                    'amount' => $lineAmount->amount(),
                    'currency' => $payment->currency,
                ]);
            }

            $this->audit->school($school, match ($data->kind) {
                PaymentCorrection::REALLOCATION => 'payment.reallocated',
                PaymentCorrection::REVERSAL => 'payment.reversed',
                default => 'payment.refunded',
            }, actor: $actor, subject: $payment, metadata: [
                'paymentCorrectionId' => $correction->id,
                'amount' => $amount->amount(),
                'currency' => $payment->currency,
                'chargeIds' => array_values(array_unique(array_map(fn (array $l) => $l[0], $lines))),
                'journalEntryId' => $posted->journalEntryId,
            ]);

            return ['correctionId' => $correction->id, 'replayed' => false];
        }));
    }

    /**
     * @param  list<string>  $chargeIds
     * @return array<string, ChargeAllocationSnapshot>
     */
    private function lockCharges(School $school, array $chargeIds): array
    {
        sort($chargeIds);
        $snapshots = [];
        foreach ($chargeIds as $chargeId) {
            try {
                $snapshots[$chargeId] = $this->charges->lockChargeForAllocation($school, $chargeId);
            } catch (ChargeNotFoundException) {
                throw PaymentCorrectionRefusedException::invalidDestination();
            }
        }

        return $snapshots;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, ChargeAllocationSnapshot>  $snapshots
     * @return array{0: list<array{0: string, 1: string, 2: Money}>, 1: list<JournalLineData>, 2: Money, 3: null}
     */
    private function planReallocation(School $school, PaymentCorrectionData $data, array $current, array $snapshots): array
    {
        $amount = $data->amount;
        if ($data->chargeId === null || $data->toChargeId === null || $data->chargeId === $data->toChargeId) {
            throw PaymentCorrectionRefusedException::invalidDestination();
        }
        if (bccomp($current[$data->chargeId] ?? '0.00', $amount->amount(), 2) < 0) {
            throw PaymentCorrectionRefusedException::exceedsPayment();
        }

        $states = $this->states->forCharges($school, [$data->chargeId, $data->toChargeId]);
        $from = $states[$data->chargeId] ?? null;
        $to = $states[$data->toChargeId] ?? null;
        if ($from === null || $to === null || $to->cancelled || $snapshots[$data->toChargeId]->isCancelled || $to->studentId !== $from->studentId) {
            throw PaymentCorrectionRefusedException::invalidDestination();
        }
        if ($to->net()->add($amount->negated())->isNegative()) {
            throw PaymentCorrectionRefusedException::destinationFull();
        }

        return [
            [[$data->chargeId, PaymentCorrectionLine::RELEASE, $amount], [$data->toChargeId, PaymentCorrectionLine::APPLY, $amount]],
            [
                new JournalLineData($snapshots[$data->chargeId]->receivableLedgerAccountId, JournalSide::Debit, $amount),
                new JournalLineData($snapshots[$data->toChargeId]->receivableLedgerAccountId, JournalSide::Credit, $amount),
            ],
            $amount,
            null,
        ];
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, ChargeAllocationSnapshot>  $snapshots
     * @return array{0: list<array{0: string, 1: string, 2: Money}>, 1: list<JournalLineData>, 2: Money, 3: string}
     */
    private function planReversal(Payment $payment, array $current, array $snapshots): array
    {
        if ($current === []) {
            throw PaymentCorrectionRefusedException::nothingToReverse();
        }

        $total = Money::of('0.00', $payment->currency);
        $lines = [];
        $journal = [];
        foreach ($current as $chargeId => $amount) {
            $money = Money::of($amount, $payment->currency);
            $total = $total->add($money);
            $lines[] = [$chargeId, PaymentCorrectionLine::RELEASE, $money];
            $journal[] = new JournalLineData($snapshots[$chargeId]->receivableLedgerAccountId, JournalSide::Debit, $money);
        }
        $journal[] = new JournalLineData($payment->settlement_ledger_account_id, JournalSide::Credit, $total);

        return [$lines, $journal, $total, $payment->settlement_ledger_account_id];
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, ChargeAllocationSnapshot>  $snapshots
     * @return array{0: list<array{0: string, 1: string, 2: Money}>, 1: list<JournalLineData>, 2: Money, 3: string}
     */
    private function planRefund(School $school, Payment $payment, PaymentCorrectionData $data, array $current, array $snapshots): array
    {
        $amount = $data->amount;
        if ($data->chargeId === null || bccomp($current[$data->chargeId] ?? '0.00', $amount->amount(), 2) < 0) {
            throw PaymentCorrectionRefusedException::exceedsPayment();
        }
        if ($data->accountId === null || ! $this->ledger->activeAccountsOfType($school, 'asset')->contains(fn ($a) => $a->ledgerAccountId === $data->accountId)) {
            throw PaymentCorrectionRefusedException::invalidAccount();
        }
        $timezone = SchoolTimezone::resolve($school);
        $today = CarbonImmutable::now($timezone)->toDateString();
        $paidOn = CarbonImmutable::parse($payment->settled_at)->setTimezone($timezone)->toDateString();
        if ($data->refundedOn === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data->refundedOn) || $data->refundedOn > $today || $data->refundedOn < $paidOn) {
            throw PaymentCorrectionRefusedException::invalidDate();
        }

        return [
            [[$data->chargeId, PaymentCorrectionLine::RELEASE, $amount]],
            [
                new JournalLineData($snapshots[$data->chargeId]->receivableLedgerAccountId, JournalSide::Debit, $amount),
                new JournalLineData($data->accountId, JournalSide::Credit, $amount),
            ],
            $amount,
            $data->accountId,
        ];
    }

    /** @return array{correctionId: string, replayed: bool} */
    private function replay(PaymentCorrection $existing, PaymentCorrectionData $data, User $actor, string $reason): array
    {
        $lines = $existing->lines->map(fn (PaymentCorrectionLine $l) => "{$l->direction}:{$l->charge_id}")->sort()->values()->all();
        $same = $existing->corrected_by_user_id === $actor->id
            && $existing->payment_id === $data->paymentId
            && $existing->kind === $data->kind
            && $existing->reason === $reason
            && ($data->kind === PaymentCorrection::REVERSAL || $existing->amount === $data->amount?->amount())
            && match ($data->kind) {
                PaymentCorrection::REALLOCATION => $lines === collect(["apply:{$data->toChargeId}", "release:{$data->chargeId}"])->sort()->values()->all(),
                PaymentCorrection::REFUND => $lines === ["release:{$data->chargeId}"] && $existing->settlement_ledger_account_id === $data->accountId,
                default => true,
            };
        if (! $same) {
            throw new PaymentCorrectionIdempotencyConflictException;
        }

        return ['correctionId' => $existing->id, 'replayed' => true];
    }

    private function reference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        return $reference === '' ? null : mb_substr($reference, 0, 64);
    }
}
