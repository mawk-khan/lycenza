<?php

namespace App\Domain\Payments\Application\Charges;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * E21.3A2: VERIFICATION ONLY. The pre-cutover computation of a charge's
 * state from every retained fact of it (all allocations, all live
 * adjustments, its current cancellation). The balance verifier compares it
 * with `ChargeStateReader`, the production read; nothing else may use it
 * (`FinanceReadCutoverGuardTest`).
 */
class AllHistoryChargeReader
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>|null  $chargeIds  null: every charge of the School
     * @return array<string, ChargeState>
     */
    public function forCharges(School $school, ?array $chargeIds = null): array
    {
        return $this->context->withSchool($school, function () use ($school, $chargeIds) {
            $facts = $this->charges->ledgerFacts($school, $chargeIds);
            $allocated = [];
            foreach (PaymentAllocation::query()->where('school_id', $school->id)
                ->when($chargeIds !== null, fn ($q) => $q->whereIn('charge_id', $chargeIds))
                ->groupBy('charge_id')->selectRaw('charge_id, sum(amount) AS total')->toBase()->get() as $row) {
                $allocated[(string) $row->charge_id] = (string) $row->total;
            }
            $adjusted = [];
            foreach ($this->charges->adjustmentLedgerFacts($school, $chargeIds) as $adjustment) {
                if (! $adjustment->cancelled) {
                    $adjusted[$adjustment->chargeId] = bcadd($adjusted[$adjustment->chargeId] ?? '0.00', $adjustment->amount, 2);
                }
            }

            $states = [];
            foreach ($facts as $fact) {
                $states[$fact->id] = new ChargeState(
                    chargeId: $fact->id,
                    studentId: $fact->studentId,
                    amount: Money::of($fact->amount, $fact->currency),
                    cancelled: $fact->cancelled,
                    allocated: Money::of($allocated[$fact->id] ?? '0.00', $fact->currency),
                    adjusted: Money::of($adjusted[$fact->id] ?? '0.00', $fact->currency),
                );
            }

            return $states;
        });
    }
}
