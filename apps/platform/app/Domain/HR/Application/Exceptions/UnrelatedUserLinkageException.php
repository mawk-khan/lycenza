<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\EmployeeService::create() is
 * asked to link an Employee to a User who has no SchoolMembership at
 * the target School. `App\Models\User` is a central/global identity
 * (docs/modules/HR.md 2.1) with no inherent relationship to any
 * School, so `employees.user_id` accepting an arbitrary valid User id
 * would let HR staff link an Employee record to a completely unrelated
 * person's account. Requiring an existing SchoolMembership at
 * link-time (not re-checked afterward -- membership status changing
 * later must not retroactively invalidate an already-established
 * linkage, per docs/modules/HR.md 2.6) is the application-level
 * invariant docs/modules/HR.md's "Database constraints" table flags as
 * something that must remain application-level, since PostgreSQL
 * cannot cleanly express "this FK target must currently satisfy an
 * unrelated table's business rule" as a static constraint without a
 * trigger that would need to re-fire indefinitely.
 */
class UnrelatedUserLinkageException extends RuntimeException
{
    public function __construct(public readonly string $userId, public readonly string $schoolId)
    {
        parent::__construct("User {$userId} has no membership at School {$schoolId} and cannot be linked as an Employee.");
    }
}
