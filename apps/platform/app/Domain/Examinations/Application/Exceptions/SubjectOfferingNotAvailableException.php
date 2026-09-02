<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * A new ExaminationPaper may only be created against an ACTIVE
 * SubjectOffering, and an existing Paper may only be reactivated
 * (inactive -> active) while its SubjectOffering is currently active.
 * Applies equally to required AND elective Offerings -- `is_required` is
 * never inspected.
 *
 * Module-local: never imported from Timetable or any other module's
 * analogous exception (CLAUDE.md rule 4).
 */
class SubjectOfferingNotAvailableException extends ExaminationException
{
    public function __construct(public readonly string $subjectOfferingId)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_SUBJECT_OFFERING_NOT_AVAILABLE', 'This SubjectOffering is not active.');
    }
}
