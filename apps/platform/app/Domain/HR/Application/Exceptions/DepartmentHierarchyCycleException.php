<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\DepartmentService::reparent()
 * is asked to set a parent that would create a cycle in the Department
 * hierarchy (the target department appears in its own would-be
 * ancestry chain). Self-parenting (`parent_department_id = id`) is
 * additionally rejected by a database CHECK constraint
 * (`hr_departments_no_self_parent_check`) as a backstop -- but an
 * INDIRECT cycle (A's new parent is B, and B's existing parent chain
 * already leads back to A) cannot be expressed as a CHECK constraint
 * (no recursion in a plain CHECK), so it is walked and rejected here,
 * at the application layer, before the write.
 */
class DepartmentHierarchyCycleException extends RuntimeException
{
    public function __construct(
        public readonly string $departmentId,
        public readonly string $proposedParentId,
    ) {
        parent::__construct("Setting Department {$departmentId}'s parent to {$proposedParentId} would create a cycle in the Department hierarchy.");
    }
}
