<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\ReportingHierarchyService::setManager()
 * is asked to make one Assignment report to a manager Assignment that
 * belongs to the SAME Employee (via a different Assignment). Rejected
 * because self-management through another Position is normally
 * semantically invalid and can create confusing hierarchy loops
 * (docs/modules/HR.md's own explicit policy for this checkpoint).
 */
class SameEmployeeReportingException extends HrException
{
    public function __construct(
        public readonly string $subordinateAssignmentId,
        public readonly string $managerAssignmentId,
    ) {
        parent::__construct(422, 'HR_SAME_EMPLOYEE_REPORTING', "Assignment {$subordinateAssignmentId} cannot report to Assignment {$managerAssignmentId} -- both belong to the same Employee.");
    }
}
