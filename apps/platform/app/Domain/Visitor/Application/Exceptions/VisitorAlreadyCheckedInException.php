<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * The common (non-racing) case: this Visitor already has an active
 * checked-in Visit. `VisitorVisitService::checkIn()` deliberately
 * REJECTS rather than auto-replacing (checkpoint brief section 5: "A
 * Visitor cannot have multiple simultaneous active visits") --
 * mirrors
 * App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException's
 * explicit-action-required precedent.
 */
class VisitorAlreadyCheckedInException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(422, 'VISITOR_ALREADY_CHECKED_IN', 'This Visitor already has an active check-in. Check them out before checking in again.');
    }
}
