<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * ADR 0069 (S1): the SubjectOffering already has dependent academic evidence
 * (an elective enrollment, a teaching assignment, a timetable entry, a
 * delivery, a register or an examination paper), so its required/elective
 * classification is frozen. Fixed text: it names no dependent record.
 */
class SubjectOfferingClassificationLockedException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'SUBJECT_OFFERING_CLASSIFICATION_LOCKED',
            'This Subject Offering already has academic records, so it cannot be changed between required and elective.',
        );
    }
}
