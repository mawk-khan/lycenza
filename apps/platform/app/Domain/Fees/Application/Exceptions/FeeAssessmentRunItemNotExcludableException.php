<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunItemNotExcludableException extends FeesException
{
    public function __construct(string $itemId)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_ITEM_NOT_EXCLUDABLE', "Only a ready preview item can be excluded; item '{$itemId}' is not ready.");
    }
}
