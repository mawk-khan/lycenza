<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeRunNotFoundException extends PaymentsException
{
    public function __construct(string $runId)
    {
        parent::__construct(404, 'LATE_FEE_RUN_NOT_FOUND', "Late-fee run '{$runId}' was not found.");
    }
}
