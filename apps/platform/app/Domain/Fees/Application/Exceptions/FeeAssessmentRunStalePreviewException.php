<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeAssessmentRunStalePreviewException extends FeesException
{
    public function __construct(string $runId)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_STALE_PREVIEW', "Assessment run '{$runId}' changed since its last preview; preview it again before executing.");
    }
}
