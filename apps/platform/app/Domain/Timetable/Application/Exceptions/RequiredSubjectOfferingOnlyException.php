<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * Only a REQUIRED SubjectOffering (`is_required = true`) may be
 * scheduled on the Timetable -- an elective (`is_required = false`) is
 * a Student-level enrollment choice
 * (`App\Domain\Students\Infrastructure\StudentSubjectEnrollment`), not
 * a fixed Section-wide weekly slot this checkpoint's scheduling model
 * represents. A future elective-scheduling model is a deliberately
 * separate concern.
 */
class RequiredSubjectOfferingOnlyException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_ELECTIVE_OFFERING_NOT_SCHEDULABLE', 'Only a required SubjectOffering may be scheduled on the Timetable.');
    }
}
