<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeRunOpenConflictException extends PaymentsException
{
    public function __construct()
    {
        parent::__construct(409, 'LATE_FEE_RUN_OPEN_EXISTS', 'This late-fee rule already has an open run; finish or cancel it first.');
    }
}
