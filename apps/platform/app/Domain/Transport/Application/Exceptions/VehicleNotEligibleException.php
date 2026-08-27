<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * An inactive Vehicle cannot receive a new operational assignment --
 * caught by an ordinary application-level check before ever attempting
 * the write, mirroring
 * App\Domain\Library\Application\Exceptions\CopyNotAvailableException's
 * shape for an equivalent "inactive reference entity" case.
 */
class VehicleNotEligibleException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_VEHICLE_NOT_ELIGIBLE', 'This Vehicle is inactive and cannot be assigned to a Route.');
    }
}
