<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmployeeAssignment;

/**
 * Small, shared collaborator (docs/modules/HR.md 8A.5 section, brief
 * section 30: "avoid circular service dependencies... use a small
 * dedicated reporting-line closure collaborator") injected by BOTH
 * App\Domain\HR\Application\EmploymentService::end() and
 * App\Domain\HR\Application\EmployeeAssignmentService::end() -- rather
 * than either service depending on the other -- so that whenever one
 * or more Assignments are closed (`ends_on` set), any OTHER currently-
 * open Assignment that pointed to one of them as its
 * `manager_assignment_id` has that now-dangling pointer cleared in the
 * same transaction. The closed Assignment's OWN `manager_assignment_id`
 * is deliberately left untouched -- "who it reported to while it was
 * open" remains valid historical fact for the period it covered.
 */
class AssignmentClosureCascade
{
    /**
     * @param  array<int, string>  $closedAssignmentIds
     */
    public function clearDanglingManagerReferences(array $closedAssignmentIds): void
    {
        if ($closedAssignmentIds === []) {
            return;
        }

        EmployeeAssignment::query()
            ->whereIn('manager_assignment_id', $closedAssignmentIds)
            ->whereNull('ends_on')
            ->update(['manager_assignment_id' => null]);
    }
}
