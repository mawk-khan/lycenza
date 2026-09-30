<?php

namespace App\Domain\Fees\Application\Exceptions;

class ChargeFullyPaidException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_FULLY_PAID', "Charge '{$chargeId}' has nothing outstanding; a concession cannot be applied to it.");
    }
}
