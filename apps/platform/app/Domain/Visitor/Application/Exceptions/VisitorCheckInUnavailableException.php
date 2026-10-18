<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * ORG.7B (ADR 0076 §34): the one check-in conflict a Campus-scoped actor
 * ever sees -- for an active Visit anywhere, including inside their own
 * scope, and for a concurrent check-in. It never names the other Campus,
 * the Visit or its state. Residual (accepted, §34.1): because a Visitor has
 * at most one active Visit in the whole School, the refusal still tells the
 * actor that the Visitor is busy somewhere.
 * Whole-School actors keep the specific conflicts
 * (VisitorAlreadyCheckedInException, ConcurrentCheckInConflictException).
 */
class VisitorCheckInUnavailableException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(409, 'VISITOR_CHECK_IN_UNAVAILABLE', 'This Visitor cannot be checked in right now.');
    }
}
