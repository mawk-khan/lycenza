<?php

namespace App\Domain\Students\Application\Exceptions;

/** ORG.8E: a high-impact lifecycle action needs one of its closed reason codes. */
class StudentLifecycleReasonException extends StudentException
{
    public function __construct(string $action)
    {
        parent::__construct(422, 'STUDENT_LIFECYCLE_REASON_INVALID', "Choose one of the listed reasons for this action ({$action}).");
    }
}
