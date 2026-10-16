<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * UAT-C3 (ADR 0075 §16): what the payment page shows about corrections --
 * where the Payment stands now on each charge, its correction history, and
 * (only for an actor who may correct) the choices the correction forms
 * offer. Read-only, under `finance.payments.view`; the choices are display
 * help only -- `PaymentCorrectionService` re-checks everything under its
 * locks.
 */
class PaymentCorrectionReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly PaymentApplicationReader $applications,
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly LedgerService $ledger,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function forPayment(School $school, string $paymentId, string $paidOn, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $paymentId, $paidOn, $actor) {
            $corrections = PaymentCorrection::query()->where('school_id', $school->id)->where('payment_id', $paymentId)
                ->with('lines')->orderBy('created_at')->orderBy('id')->get();
            $reversal = $corrections->firstWhere('kind', PaymentCorrection::REVERSAL);
            $current = $this->applications->forPayment($school, $paymentId);

            $canReallocate = $reversal === null && $this->holdsAll($actor, $school, PaymentCorrectionService::REALLOCATE_CAPABILITIES);
            $canReverseOrRefund = $reversal === null && $this->holdsAll($actor, $school, PaymentCorrectionService::REVERSE_REFUND_CAPABILITIES);

            $chargeIds = array_keys($current);
            foreach ($corrections as $correction) {
                foreach ($correction->lines as $line) {
                    $chargeIds[] = $line->charge_id;
                }
            }
            $lines = collect($this->charges->statementLinesForCharges($school, array_values(array_unique($chargeIds))))->keyBy('chargeId');
            $studentIds = $lines->filter(fn (ChargeStatementLine $l) => isset($current[$l->chargeId]))->pluck('studentId')->unique()->values()->all();

            $destinations = [];
            if ($canReallocate) {
                foreach ($studentIds as $studentId) {
                    $open = $this->charges->uncancelledChargesForStudent($school, $studentId);
                    $states = $this->states->forCharges($school, $open->pluck('chargeId')->all());
                    foreach ($open as $charge) {
                        $outstanding = ($states[$charge->chargeId] ?? null)?->outstanding() ?? Money::of($charge->amount, $charge->currency);
                        if ($outstanding->isPositive()) {
                            $destinations[] = [
                                'chargeId' => $charge->chargeId,
                                'studentId' => $studentId,
                                'description' => $charge->description,
                                'outstanding' => $outstanding->amount(),
                                'dueDate' => $charge->dueDate?->toDateString(),
                            ];
                        }
                    }
                }
            }

            return [
                'reversed' => $reversal === null ? null : [
                    'on' => CarbonImmutable::parse($reversal->created_at)->setTimezone($school->timezone)->toDateString(),
                    'reason' => $reversal->reason,
                ],
                'standing' => collect($current)->map(fn (string $amount, string $chargeId) => [
                    'chargeId' => $chargeId,
                    'studentId' => $lines->get($chargeId)?->studentId,
                    'description' => $lines->get($chargeId)?->description,
                    'amount' => $amount,
                ])->values()->all(),
                'history' => $corrections->map(fn (PaymentCorrection $c) => [
                    'id' => $c->id,
                    'kind' => $c->kind,
                    'amount' => $c->amount,
                    'reason' => $c->reason,
                    'recordedAt' => CarbonImmutable::parse($c->created_at)->toIso8601String(),
                    'recordedOn' => CarbonImmutable::parse($c->created_at)->setTimezone($school->timezone)->toDateString(),
                    'byName' => User::query()->find($c->corrected_by_user_id)?->name,
                    'journalEntryId' => $c->journal_entry_id,
                    'refundMethodLabel' => $c->refund_method === null ? null : ManualPaymentMethod::tryFrom($c->refund_method)?->label(),
                    'refundReference' => $c->refund_reference,
                    'refundedOn' => $c->refunded_on?->toDateString(),
                    'lines' => $c->lines->sortBy('direction')->map(fn (PaymentCorrectionLine $l) => [
                        'chargeId' => $l->charge_id,
                        'description' => $lines->get($l->charge_id)?->description,
                        'direction' => $l->direction,
                        'amount' => $l->amount,
                    ])->values()->all(),
                ])->values()->all(),
                'canReallocate' => $canReallocate,
                'canRecordReplacement' => $reversal !== null && $this->capabilities->canInSchool($actor, ManualPaymentRecordingService::CAPABILITY, $school),
                'studentIds' => $lines->pluck('studentId')->unique()->values()->all(),
                'canReverseOrRefund' => $canReverseOrRefund,
                'destinations' => $destinations,
                'refundAccounts' => $canReverseOrRefund
                    ? $this->ledger->activeAccountsOfType($school, 'asset')
                        ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])->values()->all()
                    : [],
                'methods' => $canReverseOrRefund ? ManualPaymentRecordingService::methodOptions() : [],
                'refundEarliest' => $paidOn,
                'today' => CarbonImmutable::now($school->timezone)->toDateString(),
            ];
        });
    }

    /** @param list<string> $capabilities */
    private function holdsAll(User $actor, School $school, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
                return false;
            }
        }

        return true;
    }
}
