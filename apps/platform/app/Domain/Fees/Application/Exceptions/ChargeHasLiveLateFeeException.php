<?php

namespace App\Domain\Fees\Application\Exceptions;

class ChargeHasLiveLateFeeException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_HAS_LIVE_LATE_FEE', "Charge '{$chargeId}' has a live late fee; void the late fee first.");
    }
}
