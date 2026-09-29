<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentAlreadyVoidedException extends FeesException
{
    public function __construct(string $assessmentId)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_ALREADY_VOIDED', "Fee assessment '{$assessmentId}' is already voided.");
    }
}
