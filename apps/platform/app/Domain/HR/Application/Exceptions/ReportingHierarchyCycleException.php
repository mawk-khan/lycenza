<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\ReportingHierarchyService::setManager()
 * is asked to set a manager that would create a cycle in the
 * reporting chain (the subordinate Assignment appears in its own
 * would-be manager ancestry). Direct self-reporting is additionally
 * rejected by a database CHECK constraint as a backstop, but an
 * INDIRECT cycle (A -> B -> C -> A) cannot be expressed as a CHECK
 * constraint -- it is walked and rejected here, at the application
 * layer, after both involved Assignment rows are locked in
 * deterministic order (see ReportingHierarchyService's own docblock).
 */
class ReportingHierarchyCycleException extends RuntimeException
{
    public function __construct(
        public readonly string $subordinateAssignmentId,
        public readonly string $proposedManagerAssignmentId,
    ) {
        parent::__construct("Setting Assignment {$subordinateAssignmentId}'s manager to {$proposedManagerAssignmentId} would create a cycle in the reporting hierarchy.");
    }
}
