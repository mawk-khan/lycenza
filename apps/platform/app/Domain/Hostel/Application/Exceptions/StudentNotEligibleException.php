<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * An inactive Student cannot receive a new Hostel residency
 * assignment. Reuses `Student::isActive()` -- the same minimal
 * "is this a going concern" eligibility precedent Transport's driver
 * check and Visitor's check-in check both already established.
 */
class StudentNotEligibleException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_STUDENT_NOT_ELIGIBLE', 'This Student is inactive and cannot be assigned a Hostel residency.');
    }
}
