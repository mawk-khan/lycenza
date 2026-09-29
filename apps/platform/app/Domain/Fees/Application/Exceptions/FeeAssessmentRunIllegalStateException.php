<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunIllegalStateException extends FeesException
{
    public function __construct(string $status, string $action)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_ILLEGAL_STATE', "An assessment run that is {$status} cannot be asked to {$action}.");
    }
}
