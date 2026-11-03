<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * The proposed reporting line would make an employment report (directly or
 * through others) to itself on some day of the line. HR.3A: the reporting
 * service walks the dated chain under the School's reporting lock, and the
 * database trigger (`trg_reporting_relationships_guard`) refuses the same
 * loop as a backstop.
 */
class ReportingHierarchyCycleException extends HrException
{
    public function __construct(
        public readonly string $subordinateEmploymentId,
        public readonly string $proposedManagerEmploymentId,
    ) {
        parent::__construct(422, 'HR_REPORTING_HIERARCHY_CYCLE', 'This reporting line would create a loop in the reporting hierarchy.');
    }
}
