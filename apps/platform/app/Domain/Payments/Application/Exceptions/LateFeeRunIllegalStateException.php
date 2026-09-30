<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeRunIllegalStateException extends PaymentsException
{
    public function __construct(string $status, string $action)
    {
        parent::__construct(409, 'LATE_FEE_RUN_ILLEGAL_STATE', "A late-fee run that is {$status} cannot {$action}.");
    }
}
