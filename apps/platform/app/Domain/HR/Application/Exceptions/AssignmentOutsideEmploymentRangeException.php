<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to create an Assignment whose `[starts_on, ends_on-or-open]`
 * interval is not fully contained within its owning EmploymentRecord's
 * own interval (docs/modules/HR.md "Database constraints": "Assignment
 * falls within owning Employment's date range" -- application-level,
 * not database-constrained).
 */
class AssignmentOutsideEmploymentRangeException extends HrException
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly string $assignmentStartsOn,
        public readonly ?string $assignmentEndsOn,
    ) {
        $range = $assignmentEndsOn === null ? "{$assignmentStartsOn} onward" : "{$assignmentStartsOn} to {$assignmentEndsOn}";
        parent::__construct(422, 'HR_ASSIGNMENT_OUTSIDE_EMPLOYMENT_RANGE', "Assignment interval ({$range}) is not contained within EmploymentRecord {$employmentRecordId}'s own interval.");
    }
}
