<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\ReportingHierarchyService::setManager()
 * is asked to make an Assignment report to itself. Also rejected by
 * the database CHECK constraint
 * (`employee_assignments_no_self_report_check`) as a backstop, but
 * this gives the caller a clean domain exception before that raw
 * QueryException would otherwise fire.
 */
class SelfReportingException extends HrException
{
    public function __construct(public readonly string $assignmentId)
    {
        parent::__construct(422, 'HR_SELF_REPORTING', "Assignment {$assignmentId} cannot report to itself.");
    }
}
