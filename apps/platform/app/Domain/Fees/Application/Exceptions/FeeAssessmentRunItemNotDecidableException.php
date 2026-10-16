<?php

namespace App\Domain\Fees\Application\Exceptions;

/** UAT-C6 (UAT-FIN-20): only an undecided "decision required" item takes an Include/Exclude decision. */
class FeeAssessmentRunItemNotDecidableException extends FeesException
{
    public function __construct()
    {
        parent::__construct(409, 'FEE_ASSESSMENT_RUN_ITEM_NOT_DECIDABLE', 'This Student does not need a decision (any more). Preview again to see the current state.');
    }
}
