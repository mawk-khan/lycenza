<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.3's transfer operation is an intra-AcademicYear placement
 * move only -- moving a Student into a Section belonging to a
 * different AcademicYear is promotion/rollover/re-enrollment territory
 * (deliberately deferred, see docs/modules/STUDENT-ENROLLMENT.md
 * "Same-AcademicYear transfer restriction"), never a disguised
 * transfer.
 */
class CrossAcademicYearTransferException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_ACADEMIC_YEAR_TRANSFER',
            'A Student Enrollment can only be transferred to a Section within the same Academic Year. Moving to a different Academic Year is promotion/rollover, not a transfer.',
        );
    }
}
