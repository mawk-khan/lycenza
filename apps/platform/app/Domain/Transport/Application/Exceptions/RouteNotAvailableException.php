<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * An inactive Route cannot receive a new Student assignment or a new
 * operational (Vehicle/Driver) assignment.
 */
class RouteNotAvailableException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_ROUTE_NOT_AVAILABLE', 'This Route is inactive and cannot receive a new assignment.');
    }
}
