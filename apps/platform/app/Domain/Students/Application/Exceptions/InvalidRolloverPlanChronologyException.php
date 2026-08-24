<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * The accepted architecture requires the target Academic Year to be
 * chronologically after the source (docs/modules/STUDENT-ENROLLMENT.md,
 * "Target Academic Year"/"chronology") -- checked using the
 * AcademicYears' own `starts_on` dates, never assumed contiguous
 * (Academic Years are guaranteed non-overlapping but may have a real
 * calendar gap between them). `EnrollmentRolloverPlanService::createDraft()`
 * only guards source != target; this plan-level chronology check is
 * dry-run's job (Phase 1B.7B), evaluated before any Item is touched.
 */
class InvalidRolloverPlanChronologyException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INVALID_ROLLOVER_PLAN_CHRONOLOGY',
            'A rollover plan\'s target Academic Year must start after the source Academic Year starts.',
        );
    }
}
