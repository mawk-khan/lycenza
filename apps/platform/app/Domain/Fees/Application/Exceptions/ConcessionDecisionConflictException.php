<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * ORG.8A-R1 (ADR 0076 §59): the decider is a Guardian of the concession's
 * Student (Rule A), so another person must decide. Fixed, generic text: it
 * never says who is whose Guardian.
 */
class ConcessionDecisionConflictException extends FeesException
{
    public function __construct(string $concessionId)
    {
        parent::__construct(403, 'FEE_CONCESSION_DECISION_CONFLICT', "Fee concession '{$concessionId}' must be decided by someone else.");
    }
}
