<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.3's safer default (no existing Academic Structure precedent
 * either permits or forbids intra-year GradeLevel movement, so this
 * checkpoint does not assume one): a same-AcademicYear transfer may
 * change Section/Campus but must NOT silently become an academic
 * promotion/reclassification. The target Section's GradeLevel must
 * equal the source Enrollment's GradeLevel; a genuine GradeLevel
 * change is deferred to a future promotion/reclassification
 * checkpoint.
 */
class IntraYearGradeChangeException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INTRA_YEAR_GRADE_CHANGE',
            'A same-Academic-Year transfer cannot change GradeLevel. That is a promotion/reclassification, not a transfer.',
        );
    }
}
