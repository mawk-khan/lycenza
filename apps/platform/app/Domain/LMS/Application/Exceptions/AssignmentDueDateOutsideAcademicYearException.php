<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * A due date must fall inside the owning SubjectOffering's
 * AcademicYear's inclusive [starts_on, ends_on] range -- mirroring
 * `AcademicTermService::assertWithinYear()`/`ExaminationService`'s
 * identical parent-range check. Unlike `CurriculumDelivery` (a record
 * of what already happened), a due date may legitimately be in the
 * future relative to today -- the same "future dates permitted and
 * expected" reasoning `Examination` already established -- so only the
 * AcademicYear boundary is checked here, never a not-in-the-past rule.
 */
class AssignmentDueDateOutsideAcademicYearException extends LmsException
{
    public function __construct(public readonly string $dueOn)
    {
        parent::__construct(422, 'ASSIGNMENT_DUE_DATE_OUTSIDE_ACADEMIC_YEAR', "Due date '{$dueOn}' falls outside the SubjectOffering's AcademicYear.");
    }
}
