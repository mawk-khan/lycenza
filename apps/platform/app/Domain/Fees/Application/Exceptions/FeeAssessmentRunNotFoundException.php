<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunNotFoundException extends FeesException
{
    public function __construct(string $runId)
    {
        parent::__construct(404, 'FEE_ASSESSMENT_RUN_NOT_FOUND', "Fee assessment run '{$runId}' was not found.");
    }
}
