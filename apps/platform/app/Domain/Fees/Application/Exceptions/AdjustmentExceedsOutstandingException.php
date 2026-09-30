<?php

namespace App\Domain\Fees\Application\Exceptions;

class AdjustmentExceedsOutstandingException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'ADJUSTMENT_EXCEEDS_OUTSTANDING', "The concession exceeds what is outstanding on charge '{$chargeId}'. It was refused, not reduced.");
    }
}
