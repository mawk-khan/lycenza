<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * This repository has no dedicated concept of "Transport eligibility"
 * -- the minimum EXISTING Student state this checkpoint requires is
 * simply App\Domain\Students\Infrastructure\Student::isActive(), the
 * exact same minimal precedent
 * App\Domain\Library\Application\Exceptions\StudentNotEligibleException
 * established, not a new configurable eligibility/consent engine.
 */
class StudentNotEligibleException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_STUDENT_NOT_ELIGIBLE', 'This Student is not active and cannot be assigned to Transport.');
    }
}
