<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunItemNotFoundException extends FeesException
{
    public function __construct(string $itemId)
    {
        parent::__construct(404, 'FEE_ASSESSMENT_RUN_ITEM_NOT_FOUND', "Fee assessment run item '{$itemId}' was not found.");
    }
}
