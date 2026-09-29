<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentNotFoundException extends FeesException
{
    public function __construct(string $assessmentId)
    {
        parent::__construct(404, 'FEE_ASSESSMENT_NOT_FOUND', "Fee assessment '{$assessmentId}' was not found.");
    }
}
