<?php

namespace App\Domain\Fees\Application\Exceptions;

class ChargeHasActiveAdjustmentsException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_HAS_ACTIVE_ADJUSTMENTS', "Charge '{$chargeId}' has active fee adjustments; cancel them first.");
    }
}
