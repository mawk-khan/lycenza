<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * ORG.7D-0 R5 (ADR 0076 §36): a new Assignment is never placed on an
 * inactive Campus -- the Campus counterpart of
 * AssignmentInactiveDepartmentException. Existing Assignments on an
 * inactive Campus are kept and can still be ended.
 */
class AssignmentInactiveCampusException extends HrException
{
    public function __construct(public readonly string $campusId)
    {
        parent::__construct(422, 'HR_ASSIGNMENT_INACTIVE_CAMPUS', 'This Campus is not active and cannot be used for a new Assignment.');
    }
}
