<?php

namespace App\Domain\Fees\Application\Exceptions;

/** UAT-C6 (UAT-FIN-20): a charge that may cover the period appeared after the preview; nothing was charged. */
class FeeAssessmentRunOverlapChangedException extends FeesException
{
    public function __construct()
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_OVERLAP_CHANGED', 'A charge that may cover this period was added after the preview. Nothing was charged. Preview again and decide for the Students it affects.');
    }
}
