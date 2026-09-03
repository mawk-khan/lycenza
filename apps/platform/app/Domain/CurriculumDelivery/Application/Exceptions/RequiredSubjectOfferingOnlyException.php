<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * Only a REQUIRED SubjectOffering (`is_required = true`) may have
 * per-Section curriculum delivery recorded against it. An elective
 * (`is_required = false`) is a Student-level enrollment choice
 * (`StudentSubjectEnrollment`, which carries no `section_id`), not a
 * Section-wide cohort, so "Section A covered Unit 3" has no
 * well-defined meaning for one.
 *
 * Deliberately its OWN class, not an import of
 * App\Domain\Timetable\Application\Exceptions\RequiredSubjectOfferingOnlyException
 * despite the near-identical semantics: reaching into another module's
 * Application namespace is exactly the coupling CLAUDE.md rule 4
 * forbids, and Timetable's restriction exists for scheduling while
 * this one exists for cohort coverage. The two must be free to diverge.
 */
class RequiredSubjectOfferingOnlyException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $subjectOfferingId)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_REQUIRED_OFFERING_ONLY', 'Curriculum delivery can only be recorded against a required SubjectOffering; an elective has no Section-wide cohort.');
    }
}
