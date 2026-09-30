<?php

namespace App\Domain\Fees\Application\Exceptions;

class ChargeIsLateFeeException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_IS_LATE_FEE', "Charge '{$chargeId}' is a late fee; void its late-fee assessment to cancel it.");
    }
}
