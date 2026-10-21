<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Payments\Application\Access\PaymentCampusVisibility;
use App\Domain\Payments\Application\Exceptions\PaymentReceiptNotFoundException;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * FEE.4 (ADR 0062 §17, §19, §21): the authorized read of one Payment's
 * receipt, under `finance.payments.view` (no receipt capability). Receipts
 * are Highly Sensitive Student financial data: each read is audited once
 * (`payment_receipt.viewed`, ids only). A Payment of another School, a
 * missing Payment and a Payment without a receipt are the same not-found.
 * Charge facts (description, fee head, billing period) come through Fees'
 * `ChargeService`, never its tables.
 */
class PaymentReceiptReadService
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly PaymentCampusVisibility $visibility,
    ) {}

    /**
     * ORG.7G-1 (ADR 0076 §50, G1-D5): a receipt is visible exactly when its
     * Payment is (PaymentCampusVisibility) -- never by Student, sequence or
     * Enrollment; an invisible one is the same "not found" as an unknown one.
     */
    public function forPayment(School $school, string $paymentId, User $actor): ReceiptDocument
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $scope, $paymentId, $actor) {
            $payment = Str::isUuid($paymentId)
                ? $this->visibility->restrict(Payment::query()->where('payments.school_id', $school->id), $school, $scope)->with(['receipt', 'allocations'])->find($paymentId)
                : null;
            $receipt = $payment?->receipt;
            if ($payment === null || $receipt === null) {
                throw new PaymentReceiptNotFoundException($paymentId);
            }

            $facts = collect($this->charges->statementLinesForCharges($school, $payment->allocations->pluck('charge_id')->all()))
                ->keyBy(fn (ChargeStatementLine $l) => $l->chargeId);

            $this->audit->school($school, 'payment_receipt.viewed', actor: $actor, subject: $receipt, metadata: [
                'paymentReceiptId' => $receipt->id,
                'paymentId' => $payment->id,
            ]);

            return new ReceiptDocument(
                receiptId: $receipt->id,
                receiptNumber: $receipt->receipt_number,
                seriesKey: $receipt->series_key,
                sequenceValue: $receipt->sequence_value,
                issuedAt: $receipt->issued_at,
                paymentId: $payment->id,
                source: $payment->source,
                method: $payment->method,
                manualReference: $payment->manual_reference,
                amount: $payment->amount,
                currency: $payment->currency,
                settledAt: $payment->settled_at,
                studentIds: $facts->pluck('studentId')->unique()->values()->all(),
                lines: $payment->allocations->sortBy('charge_id')->map(function (PaymentAllocation $a) use ($facts) {
                    /** @var ChargeStatementLine|null $fact */
                    $fact = $facts->get($a->charge_id);

                    return [
                        'chargeId' => $a->charge_id,
                        'amount' => $a->amount,
                        'description' => $fact?->description,
                        'feeHeadName' => $fact?->feeHeadName,
                        'billingPeriodKey' => $fact?->billingPeriodKey,
                        'billingPeriodLabel' => $fact?->billingPeriodLabel,
                        'academicYearId' => $fact?->academicYearId,
                    ];
                })->values()->all(),
                // UAT-C3: the receipt keeps its number and original lines; a
                // later reversal, refund or reallocation is shown beside it.
                corrections: PaymentCorrection::query()->where('school_id', $school->id)->where('payment_id', $payment->id)
                    ->orderBy('created_at')->orderBy('id')->get()
                    ->map(fn (PaymentCorrection $c) => ['kind' => $c->kind, 'amount' => $c->amount, 'recordedAt' => $c->created_at])
                    ->values()->all(),
            );
        });
    }
}
