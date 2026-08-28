<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1G.1: subject rollover mappings represent EXPLICIT elective
 * carry-forward only (docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md
 * section 2) -- a REQUIRED SubjectOffering's roster is always DERIVED
 * from the target StudentEnrollment (SubjectOfferingRosterReadService),
 * never carried forward via an explicit mapping row. Deliberately a
 * distinct exception from RequiredSubjectOfferingEnrollmentException:
 * that one rejects an attempted Student enrollment; this one rejects a
 * rollover CONFIGURATION mutation, a different call path with a
 * different actor and a different corrective action.
 */
class RequiredSubjectOfferingRolloverMappingException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'REQUIRED_SUBJECT_OFFERING_ROLLOVER_MAPPING',
            'A required SubjectOffering cannot be used as a subject rollover mapping\'s source or target -- required rosters are always derived, never explicitly mapped.',
        );
    }
}
