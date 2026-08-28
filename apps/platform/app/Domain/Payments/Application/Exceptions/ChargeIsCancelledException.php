<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 (rule 47): a cancelled Charge may not receive a new
 * payment allocation unless a future checkpoint explicitly allows it --
 * FINANCE.md does not, so 0G.5 rejects outright.
 * `App\Domain\Fees\Application\ChargeService::lockChargeForAllocation()`'s
 * row lock is what makes this coherent under a real allocate-vs-cancel
 * race (rule 48): whichever operation reaches the Charge row first wins,
 * and the other observes the final, locked-and-committed state.
 */
class ChargeIsCancelledException extends PaymentsException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(
            409,
            'CHARGE_IS_CANCELLED',
            "Charge '{$chargeId}' is cancelled and cannot receive a new payment allocation."
        );
    }
}
