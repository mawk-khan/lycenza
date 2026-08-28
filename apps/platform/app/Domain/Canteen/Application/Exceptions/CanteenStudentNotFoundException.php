<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * Raised uniformly whether a caller-supplied `studentId` genuinely
 * does not exist OR exists only in a different School -- mirrors
 * App\Domain\Fees\Application\Exceptions\StudentNotFoundException's
 * exact "no oracle" cross-School reasoning. Distinct from
 * CanteenStudentNotEligibleException (this Student exists but is not
 * active).
 */
class CanteenStudentNotFoundException extends CanteenException
{
    public function __construct(public readonly string $studentId)
    {
        parent::__construct(404, 'CANTEEN_STUDENT_NOT_FOUND', "No student with id '{$studentId}' was found in this School.");
    }
}
