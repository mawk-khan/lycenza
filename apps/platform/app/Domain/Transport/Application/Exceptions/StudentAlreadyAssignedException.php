<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * The common (non-racing) case: the Student already has an active
 * Transport assignment. TransportStudentAssignmentService::assign()
 * deliberately REJECTS rather than auto-replacing (checkpoint brief:
 * "a Student's Transport assignment is a discrete fact worth an
 * explicit transition") -- the caller must explicitly end() the
 * current assignment first, mirroring
 * App\Domain\Library\Application\Exceptions\CopyNotAvailableException's
 * explicit-action-required precedent rather than
 * AcademicYearService::activate()'s auto-replace precedent.
 */
class StudentAlreadyAssignedException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_STUDENT_ALREADY_ASSIGNED', 'This Student already has an active Transport assignment. End it before assigning a new one.');
    }
}
