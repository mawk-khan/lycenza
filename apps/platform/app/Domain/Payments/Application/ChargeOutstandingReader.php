<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeAllocationSnapshot;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Support\Money\Money;

/**
 * FEE.5 (ADR 0062 §15, §16.2; owner decision H): a charge's CURRENT
 * outstanding -- `amount - valid payment allocations - live fee
 * adjustments` -- computed by Payments, which owns allocations; adjustment
 * totals come through Fees' `ChargeService`. `locked()` takes the charge's
 * row lock first (the lock the allocation and adjustment paths take), so
 * an execution-time outstanding cannot be raced by a payment or a
 * concession.
 */
class ChargeOutstandingReader
{
    public function __construct(private readonly ChargeService $charges) {}

    /**
     * Preview only (no lock).
     *
     * @param  array<string, Money>  $amounts  charge id => charge amount
     * @return array<string, Money> charge id => outstanding
     */
    public function forCharges(School $school, array $amounts): array
    {
        if ($amounts === []) {
            return [];
        }

        $ids = array_keys($amounts);
        $allocated = PaymentAllocation::query()
            ->where('school_id', $school->id)
            ->whereIn('charge_id', $ids)
            ->groupBy('charge_id')
            ->selectRaw('charge_id, sum(amount) as total')
            ->pluck('total', 'charge_id');
        $adjusted = $this->charges->liveAdjustmentTotalsFor($school, $ids);

        $outstanding = [];
        foreach ($amounts as $id => $amount) {
            $outstanding[$id] = $amount
                ->add(Money::of((string) ($allocated[$id] ?? '0.00'), $amount->currency())->negated())
                ->add(($adjusted[$id] ?? Money::of('0.00', $amount->currency()))->negated());
        }

        return $outstanding;
    }

    /** @return array{snapshot: ChargeAllocationSnapshot, outstanding: Money} under the charge row lock */
    public function locked(School $school, string $chargeId): array
    {
        $snapshot = $this->charges->lockChargeForAllocation($school, $chargeId);
        $allocated = (string) PaymentAllocation::query()->where('school_id', $school->id)->where('charge_id', $chargeId)->sum('amount');

        return [
            'snapshot' => $snapshot,
            'outstanding' => $snapshot->netAmount()->add(Money::of($allocated, $snapshot->amount->currency())->negated()),
        ];
    }
}
