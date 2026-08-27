<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * Phase 0G.5 (rule 12/46): a Charge with any recognized payment
 * allocation can never be cancelled -- cancelling would reverse
 * recognized revenue while settled money already sits in a settlement
 * ledger account with no compensating Refund model (deferred). Raised by
 * `App\Domain\Fees\Application\ChargeService::cancel()` after
 * translating the `charges_payment_allocation_guard_trigger` database
 * rejection (`App\Domain\Payments`' migration, never a direct
 * Fees->Payments Application-layer read -- see that migration's
 * docblock).
 */
class ChargeHasPaymentAllocationsException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(
            409,
            'CHARGE_HAS_PAYMENT_ALLOCATIONS',
            "Charge '{$chargeId}' cannot be cancelled because it has recognized payment allocations."
        );
    }
}
