<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAdjustmentNotFoundException extends FeesException
{
    public function __construct(string $adjustmentId)
    {
        parent::__construct(404, 'FEE_ADJUSTMENT_NOT_FOUND', "Fee adjustment '{$adjustmentId}' was not found.");
    }
}
