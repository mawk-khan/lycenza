<?php

namespace App\Domain\Fees\Application\Exceptions;

/** UAT-C6 (UAT-FIN-20): a run cannot execute while a Student still needs an Include/Exclude decision. */
class FeeAssessmentRunUnresolvedOverlapException extends FeesException
{
    public function __construct(int $count)
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_OVERLAP_UNRESOLVED', $count === 1
            ? '1 Student already has a charge that may cover this period. Choose "Include anyway" or "Exclude from this run" for them, preview again, then execute.'
            : "{$count} Students already have a charge that may cover this period. Choose \"Include anyway\" or \"Exclude from this run\" for each, preview again, then execute.");
    }
}
