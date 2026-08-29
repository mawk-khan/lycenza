<?php

namespace App\Domain\Timetable\Application\Exceptions;

class SubjectOfferingNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_SUBJECT_OFFERING_NOT_AVAILABLE', 'This SubjectOffering is not active and cannot be scheduled.');
    }
}
