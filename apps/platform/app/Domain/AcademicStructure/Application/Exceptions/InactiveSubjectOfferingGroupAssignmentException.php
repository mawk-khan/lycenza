<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SWC.3 (ADR 0073 section 19.1, owner decision 2026-10-14): an INACTIVE
 * Subject Offering may not be newly assigned to an Elective Group. This
 * tightens the existing eligibility invariant. An Offering already in a
 * group stays there (and stays readable) when it is later deactivated, and
 * removing it remains governed by the existing participation-history rule.
 */
class InactiveSubjectOfferingGroupAssignmentException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INACTIVE_SUBJECT_OFFERING_GROUP_ASSIGNMENT',
            'An inactive Subject Offering cannot be assigned to an Elective Group. Reactivate it first.',
        );
    }
}
