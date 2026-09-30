<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAdjustmentAlreadyCancelledException extends FeesException
{
    public function __construct(string $adjustmentId)
    {
        parent::__construct(409, 'FEE_ADJUSTMENT_ALREADY_CANCELLED', "Fee adjustment '{$adjustmentId}' is already cancelled.");
    }
}
