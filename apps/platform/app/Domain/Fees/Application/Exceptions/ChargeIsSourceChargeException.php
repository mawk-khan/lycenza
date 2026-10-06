<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * OPF.4 (ADR 0067 §17, D4): an operational source's event charge (a Library
 * fine) is cancelled only by voiding it at its source, which records the
 * void and cancels the charge in one transaction.
 */
class ChargeIsSourceChargeException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_IS_SOURCE_CHARGE', "Charge '{$chargeId}' is an operational source charge; void it at its source to cancel it.");
    }
}
