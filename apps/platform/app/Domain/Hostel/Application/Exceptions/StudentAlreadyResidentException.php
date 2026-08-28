<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * The common (non-racing) case: this Student already has an active
 * Hostel residency. `HostelResidencyService::assign()` deliberately
 * REJECTS rather than silently transferring the Student -- checkpoint
 * brief section 18: "Do not silently transfer a Student between
 * Beds" -- mirroring
 * App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException's
 * explicit-action-required precedent.
 */
class StudentAlreadyResidentException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_STUDENT_ALREADY_RESIDENT', 'This Student already has an active Hostel residency. End it before assigning a new one.');
    }
}
