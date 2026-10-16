<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * UAT-C4 (UAT-FIN-11): an ad-hoc charge names a receivable account that is
 * not an active asset account, or a revenue account that is not an active
 * income account, of the School -- the same rule every fee head already
 * follows (FEE.1, `FeeHeadService`). `field` names the offending input.
 */
class InvalidChargeAccountException extends FeesException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct(422, 'CHARGE_ACCOUNT_INVALID', $message);
    }
}
