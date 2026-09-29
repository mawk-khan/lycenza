<?php

namespace App\Domain\Fees\Application\Exceptions;

class ChargeIsFeeAssessedException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_IS_FEE_ASSESSED', "Charge '{$chargeId}' was produced by a fee assessment; void the fee assessment to cancel it.");
    }
}
