<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
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
 * - Staff only: no export, PDF, email or Guardian/Student view.
 */
class StudentFeeStatementReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function statementFor(School $school, string $studentId, ?string $academicYearId, User $actor): StudentFeeStatement
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);
        $this->authorizeCapabilityFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $studentId, $academicYearId, $actor) {
            $facts = $this->charges->statementLinesForStudent($school, $studentId, $academicYearId);
            $chargeIds = array_map(fn (ChargeStatementLine $l) => $l->chargeId, $facts);

            $allocations = $chargeIds === [] ? collect() : PaymentAllocation::query()
                ->where('school_id', $school->id)
                ->whereIn('charge_id', $chargeIds)
                ->get();
            $payments = Payment::query()
                ->whereIn('id', $allocations->pluck('payment_id')->unique()->all())
                ->with('receipt')
                ->get()
                ->keyBy('id');

            $currency = 'INR';
            $totals = ['charged' => Money::of('0.00', $currency), 'adjusted' => Money::of('0.00', $currency), 'paid' => Money::of('0.00', $currency), 'outstanding' => Money::of('0.00', $currency)];
            $lines = [];

            foreach ($facts as $fact) {
                $amount = Money::of($fact->amount, $fact->currency);
                $adjusted = Money::of($fact->liveAdjustedTotal, $fact->currency);
                $paid = Money::of('0.00', $fact->currency);
                $rows = [];

                foreach ($allocations->where('charge_id', $fact->chargeId)->sortBy(fn (PaymentAllocation $a) => ($payments->get($a->payment_id)?->settled_at->format('Y-m-d H:i:s') ?? '').$a->payment_id) as $allocation) {
                    $payment = $payments->get($allocation->payment_id);
                    $paid = $paid->add(Money::of($allocation->amount, $allocation->currency));
                    $rows[] = [
                        'paymentId' => $allocation->payment_id,
                        'amount' => $allocation->amount,
                        'settledAt' => $payment?->settled_at->toIso8601String(),
                        'method' => $payment?->method,
                        'source' => $payment?->source,
                        'receiptNumber' => $payment?->receipt?->receipt_number,
                    ];
                }

                $cancelled = $fact->cancelledAt !== null;
                $outstanding = $cancelled ? Money::of('0.00', $fact->currency) : $amount->add($paid->negated())->add($adjusted->negated());

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
            );
        });
    }
}
