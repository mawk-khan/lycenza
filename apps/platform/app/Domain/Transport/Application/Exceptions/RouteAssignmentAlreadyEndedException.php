<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * An end was attempted against a Route operational assignment that is
 * already `ended` -- detected via a conditional
 * `UPDATE ... WHERE status = 'active'` affecting zero rows, mirroring
 * App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException.
 */
class RouteAssignmentAlreadyEndedException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_ROUTE_ASSIGNMENT_ALREADY_ENDED', 'This Route operational assignment has already ended.');
    }
}
