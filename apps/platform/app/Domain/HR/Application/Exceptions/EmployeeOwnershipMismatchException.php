<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when an address/emergency-contact service method is asked to
 * update/remove/promote a child record that does not actually belong to
 * the Employee the caller supplied. School OS rules 19/24: an
 * `employee_id` route/request parameter is never trusted at face value
 * -- ownership is re-verified server-side against the record's own
 * `employee_id`, exactly like `SchoolSwitchController` re-verifies a
 * real `SchoolMembership` rather than trusting a client-supplied
 * `school_id`. This is what keeps a caller from reassigning a child
 * record to a different Employee (an IDOR-shaped bug) merely by
 * supplying two different, individually-valid ids in the same request.
 */
class EmployeeOwnershipMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $recordId,
        public readonly string $expectedEmployeeId,
        public readonly string $actualEmployeeId,
    ) {
        parent::__construct("Record {$recordId} belongs to Employee {$actualEmployeeId}, not the expected Employee {$expectedEmployeeId}.");
    }
}
