<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * Checkpoint brief: "Driver must reference an existing HR Employee...
 * capability-based authorization controls who manages Transport, not
 * job title." This checkpoint's minimum eligibility check is simply
 * App\Domain\HR\Infrastructure\Employee::isActive() (record_status) --
 * the same minimal-existing-state precedent
 * App\Domain\Library\Application\Exceptions\StudentNotEligibleException
 * established for Student::isActive(), not a new configurable
 * eligibility engine and not a dependency on any specific Position/job
 * title.
 */
class DriverNotEligibleException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_DRIVER_NOT_ELIGIBLE', 'This Employee is not active and cannot be assigned as a driver.');
    }
}
