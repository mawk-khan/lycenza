<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * A Visit that is already `checked_out` cannot be checked out again --
 * mirrors
 * App\Domain\Transport\Application\Exceptions\StudentAssignmentAlreadyEndedException
 * exactly. `VisitorVisitService::checkOut()`'s conditional
 * `UPDATE ... WHERE status = 'checked_in'` is what makes this safe
 * under concurrent/retried requests (checkpoint brief section 15).
 */
class VisitAlreadyCheckedOutException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(422, 'VISITOR_VISIT_ALREADY_CHECKED_OUT', 'This Visit has already been checked out.');
    }
}
