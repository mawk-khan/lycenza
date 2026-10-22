<?php

namespace App\Domain\Students\Application\Exceptions;

/** ORG.8E: deactivating an inactive Student or reactivating an active one changes nothing and records nothing. */
class StudentStatusUnchangedException extends StudentException
{
    public function __construct(string $status)
    {
        parent::__construct(409, 'STUDENT_STATUS_UNCHANGED', "The Student is already {$status}.");
    }
}
