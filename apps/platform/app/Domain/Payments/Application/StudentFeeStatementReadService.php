<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * FEE.4 (ADR 0062 §18): the staff Student fee statement, owned by Payments
 * (it depends on allocations) and computed on read from authoritative rows
 * -- no stored balance.
 *
 * - Authorization: `finance.charges.view` AND `finance.payments.view`
 *   (both, never either); no statement capability exists.
 * - Charges, cancellations, fee head and billing period (from the fee
 *   assessment) and adjustments come through Fees'
 *   `ChargeService::statementLinesForStudent()`; allocations, Payments and
 *   receipt numbers are Payments' own rows.
 * - Per charge: outstanding = amount - allocations - live adjustments (the
 *   FEE.3 net model); a cancelled charge contributes nothing.
 * - One `fee_statement.viewed` audit per successful view (ids only), none
 *   on a denial.
 * - FEE.5: a late fee is an ordinary charge line; live links are shown both
 *   ways (`lateFeeSourceChargeId` on the late fee, `lateFeeChargeIds` on
 *   its source).
 * - Staff only: no export, PDF, email or Guardian/Student view.
 */
class StudentFeeStatementReadService
{
    use AuthorizesCampusScope, AuthorizesCapability;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ChargeStateReader $states,
        private readonly FinancialPeriodService $periods,
    ) {}

    public function statementFor(School $school, string $studentId, ?string $academicYearId, User $actor): StudentFeeStatement
    {
        $this->authorizeWholeSchoolScopeFor($actor, 'finance.charges.view', $school);
        $this->authorizeWholeSchoolScopeFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $studentId, $academicYearId, $actor) {
            $facts = $this->charges->statementLinesForStudent($school, $studentId, $academicYearId);
            $chargeIds = array_map(fn (ChargeStatementLine $l) => $l->chargeId, $facts);

            $allocations = $chargeIds === [] ? collect() : PaymentAllocation::query()
                ->where('school_id', $school->id)
                ->whereIn('charge_id', $chargeIds)
                ->get();
            // UAT-C3: correction lines on these charges -- shown as their own
            // rows (history is never rewritten); amounts already come through
            // the charge-state read below.
            $corrections = $chargeIds === [] ? collect() : PaymentCorrectionLine::query()
                ->where('school_id', $school->id)
                ->whereIn('charge_id', $chargeIds)
                ->with('correction')
                ->get();
            $payments = Payment::query()
                ->whereIn('id', $allocations->pluck('payment_id')->merge($corrections->pluck('payment_id'))->unique()->all())
                ->with('receipt')
                ->get()
                ->keyBy('id');

            // FEE.5: late-fee links in both directions (Payments' own rows).
            $lateFees = $chargeIds === [] ? collect() : LateFeeAssessment::query()
                ->where(fn ($q) => $q->whereIn('charge_id', $chargeIds)->orWhereIn('source_charge_id', $chargeIds))
                ->whereNull('voided_at')
                ->get();

            $currency = 'INR';
            $totals = ['charged' => Money::of('0.00', $currency), 'adjusted' => Money::of('0.00', $currency), 'paid' => Money::of('0.00', $currency), 'outstanding' => Money::of('0.00', $currency)];
            $lines = [];

            // E21.3A2: amounts through the carry-forward read; the payment
            // rows below are the retained detail behind them.
            $states = $this->states->forCharges($school, $chargeIds);

            foreach ($facts as $fact) {
                $state = $states[$fact->chargeId];
                $amount = Money::of($fact->amount, $fact->currency);
                $adjusted = $state->adjusted;
                $paid = $state->allocated;
                $rows = [];

                foreach ($allocations->where('charge_id', $fact->chargeId)->sortBy(fn (PaymentAllocation $a) => ($payments->get($a->payment_id)?->settled_at->format('Y-m-d H:i:s') ?? '').$a->payment_id) as $allocation) {
                    $payment = $payments->get($allocation->payment_id);
                    $rows[] = [
                        'kind' => 'payment',
                        'paymentId' => $allocation->payment_id,
                        'amount' => $allocation->amount,
                        'settledAt' => $payment?->settled_at->toIso8601String(),
                        'method' => $payment?->method,
                        'source' => $payment?->source,
                        'receiptNumber' => $payment?->receipt?->receipt_number,
                    ];
                }
                foreach ($corrections->where('charge_id', $fact->chargeId)->sortBy(fn (PaymentCorrectionLine $l) => $l->created_at->format('Y-m-d H:i:s.u').$l->id) as $line) {
                    $payment = $payments->get($line->payment_id);
                    $rows[] = [
                        // reallocation_in / reallocation_out / reversal / refund
                        'kind' => $line->correction->kind === PaymentCorrection::REALLOCATION
                            ? ($line->direction === PaymentCorrectionLine::APPLY ? 'reallocation_in' : 'reallocation_out')
                            : $line->correction->kind,
                        'paymentId' => $line->payment_id,
                        'amount' => $line->direction === PaymentCorrectionLine::APPLY ? $line->amount : '-'.$line->amount,
                        'settledAt' => $line->created_at->toIso8601String(),
                        'method' => $payment?->method,
                        'source' => $payment?->source,
                        'receiptNumber' => $payment?->receipt?->receipt_number,
                        'reason' => $line->correction->reason,
                    ];
                }

                $cancelled = $state->cancelled;
                $outstanding = $state->outstanding();

                if (! $cancelled) {
                    $totals['charged'] = $totals['charged']->add($amount);
                    $totals['adjusted'] = $totals['adjusted']->add($adjusted);
                }
                $totals['paid'] = $totals['paid']->add($paid);
                $totals['outstanding'] = $totals['outstanding']->add($outstanding);

                $lines[] = [
                    'chargeId' => $fact->chargeId,
                    'academicYearId' => $fact->academicYearId,
                    'description' => $fact->description,
                    'feeHeadId' => $fact->feeHeadId,
                    'feeHeadName' => $fact->feeHeadName,
                    'billingPeriodKey' => $fact->billingPeriodKey,
                    'billingPeriodLabel' => $fact->billingPeriodLabel,
                    'dueDate' => $fact->dueDate,
                    'assessedAt' => $fact->assessedAt->toIso8601String(),
                    'cancelledAt' => $fact->cancelledAt?->toIso8601String(),
                    'amount' => $amount->amount(),
                    'adjustedTotal' => $adjusted->amount(),
                    'paidTotal' => $paid->amount(),
                    'outstanding' => $outstanding->amount(),
                    'currency' => $fact->currency,
                    'adjustments' => $fact->adjustments,
                    'payments' => $rows,
                    'lateFeeSourceChargeId' => $lateFees->firstWhere('charge_id', $fact->chargeId)?->source_charge_id,
                    'lateFeeChargeIds' => $lateFees->where('source_charge_id', $fact->chargeId)->pluck('charge_id')->values()->all(),
                ];
            }

            $this->audit->school($school, 'fee_statement.viewed', actor: $actor, metadata: [
                'studentId' => $studentId,
                'academicYearId' => $academicYearId,
                'lineCount' => count($lines),
            ]);

            return new StudentFeeStatement(
                studentId: $studentId,
                academicYearId: $academicYearId,
                currency: $currency,
                lines: $lines,
                totals: array_map(fn (Money $m) => $m->amount(), $totals),
                detailExpiredThrough: $this->periods->expiryAnchor($school)?->key,
            );
        });
    }
}
