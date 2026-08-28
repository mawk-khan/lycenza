<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * The common (non-racing) case: this Bed already has an active
 * resident.
 */
class BedAlreadyOccupiedException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_BED_ALREADY_OCCUPIED', 'This Bed already has an active resident.');
    }
}
