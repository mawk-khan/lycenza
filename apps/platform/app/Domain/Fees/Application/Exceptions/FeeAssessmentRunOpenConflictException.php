<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunOpenConflictException extends FeesException
{
    public function __construct(string $billingPeriodKey)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_OPEN_EXISTS', "An open assessment run already exists for this fee structure and billing period '{$billingPeriodKey}'.");
    }
}
