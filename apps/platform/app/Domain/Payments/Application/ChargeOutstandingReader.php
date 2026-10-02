<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeAllocationSnapshot;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Models\School;
use App\Support\Money\Money;

/**
 * FEE.5 (ADR 0062 §15, §16.2; owner decision H): a charge's CURRENT
 * outstanding -- `amount - valid payment allocations - live fee
 * adjustments`. `locked()` takes the charge's row lock first (the lock the
 * allocation and adjustment paths take), so an execution-time outstanding
 * cannot be raced by a payment or a concession.
 *
 * E21.3A2: both read through `ChargeStateReader` (latest closed carried
 * state + later facts), never the whole history.
 */
class ChargeOutstandingReader
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
    ) {}

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

        $states = $this->states->forCharges($school, array_map('strval', array_keys($amounts)));

        $outstanding = [];
        foreach ($amounts as $id => $amount) {
            $state = $states[$id] ?? null;
            $outstanding[$id] = $state === null
                ? $amount
                : $amount->add($state->allocated->negated())->add($state->adjusted->negated());
        }

        return $outstanding;
    }

    /** @return array{snapshot: ChargeAllocationSnapshot, outstanding: Money} under the charge row lock */
    public function locked(School $school, string $chargeId): array
    {
        $snapshot = $this->charges->lockChargeForAllocation($school, $chargeId);
        $state = $this->states->forCharges($school, [$chargeId])[$chargeId];

        return [
            'snapshot' => $snapshot,
            'outstanding' => $state->net(),
        ];
    }
}
