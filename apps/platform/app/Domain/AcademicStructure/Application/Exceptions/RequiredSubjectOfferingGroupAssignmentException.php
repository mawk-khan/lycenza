<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 1F.3: a REQUIRED SubjectOffering (`is_required = true`) may
 * never join an ElectiveGroup (architecture doc §9, unchanged from
 * Phase 1F.0) -- deliberately distinct from
 * App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingEnrollmentException,
 * which is a Student PARTICIPATION error; this is an AcademicStructure
 * CONFIGURATION error, a different domain operation entirely. The
 * database CHECK constraint (`subject_offerings_required_group_check`)
 * remains the structural backstop.
 */
class RequiredSubjectOfferingGroupAssignmentException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'REQUIRED_SUBJECT_OFFERING_GROUP_ASSIGNMENT',
            'A required SubjectOffering cannot be assigned to an ElectiveGroup.',
        );
    }
}
