<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * An end was attempted against a Student Transport assignment that is
 * already `ended` -- detected via a conditional
 * `UPDATE ... WHERE status = 'active'` affecting zero rows, mirroring
 * App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException.
 */
class StudentAssignmentAlreadyEndedException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_STUDENT_ASSIGNMENT_ALREADY_ENDED', 'This Student Transport assignment has already ended.');
    }
}
