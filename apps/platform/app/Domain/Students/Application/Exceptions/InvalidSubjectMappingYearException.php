<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1G.1 (docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md
 * section 5's "Recommended schema strengthening", accepted division):
 * `enrollment_rollover_subject_mappings` deliberately does NOT
 * denormalize the plan's source/target AcademicYear id onto the
 * mapping row merely to express this as a database FK, so
 * `EnrollmentRolloverPlanService::upsertSubjectMapping()` is the
 * authoritative enforcement point instead -- a source SubjectOffering
 * must belong to the plan's `source_academic_year_id`; a non-null
 * target SubjectOffering must belong to the plan's
 * `target_academic_year_id`. No automatic lookup/inference is ever
 * attempted for either side.
 */
class InvalidSubjectMappingYearException extends StudentException
{
    public function __construct(string $side)
    {
        parent::__construct(
            422,
            'INVALID_SUBJECT_MAPPING_YEAR',
            "The {$side} SubjectOffering does not belong to the rollover plan's {$side} Academic Year.",
        );
    }
}
