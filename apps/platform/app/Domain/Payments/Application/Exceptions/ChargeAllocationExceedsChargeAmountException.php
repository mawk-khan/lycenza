<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 (rule 31): a Charge's cumulative allocations (existing +
 * this operation's new allocation) may never exceed its own `amount`.
 * The `SELECT ... FOR UPDATE` lock
 * `App\Domain\Fees\Application\ChargeService::lockChargeForAllocation()`
 * takes is the primary concurrency-safety mechanism; the database's own
 * `payment_allocations_charge_not_overallocated_check` deferred
 * constraint trigger is the authoritative backstop regardless.
 */
class ChargeAllocationExceedsChargeAmountException extends PaymentsException
{
    public function __construct(string $chargeId, string $attemptedTotal, string $chargeAmount)
    {
        parent::__construct(
            422,
            'CHARGE_ALLOCATION_EXCEEDS_CHARGE_AMOUNT',
            "Allocating '{$attemptedTotal}' to charge '{$chargeId}' would exceed its amount '{$chargeAmount}'."
        );
    }
}
