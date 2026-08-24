<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection
 * (`enrollment_rollover_plans_source_academic_year_id_school_id_for`/
 * `_target_academic_year_id_school_id_for`, Phase 1B.7A) -- this
 * service never relies on that FK failure alone to catch a source/
 * target AcademicYear from a different School than the plan itself;
 * it checks first and fails with a clean domain error, mirroring
 * CrossSchoolEnrollmentException's identical rationale.
 */
class CrossSchoolRolloverPlanException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_ROLLOVER_PLAN',
            'A rollover plan\'s source and target Academic Years must belong to the same School as the plan.',
        );
    }
}
