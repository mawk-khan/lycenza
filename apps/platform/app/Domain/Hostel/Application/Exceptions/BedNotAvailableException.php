<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * The Bed cannot receive a new assignment because it, its Room, or its
 * Hostel is inactive (docs/modules/HOSTEL.md "Room/Bed status
 * interactions") -- deliberately ONE exception for the whole ancestor
 * chain, mirroring `RouteNotAvailableException`'s simplicity; the
 * chain-position of the inactive ancestor is not exposed as a
 * separate machine code because no caller-observable distinction was
 * required for this checkpoint.
 */
class BedNotAvailableException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_BED_NOT_AVAILABLE', 'This Bed is not available for assignment (it, its Room, or its Hostel is inactive).');
    }
}
