<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\HR\Infrastructure\Employee::booted()'s
 * `updating` guard when any code path attempts to change an already-
 * persisted `employee_number`. docs/modules/HR.md ("Employee
 * identifier strategy"): the number is immutable after allocation --
 * an intentional future correction workflow (e.g. a data-entry error
 * caught the same day) is out of scope for Phase 8A.1 and would need
 * its own explicit, audited action in a later checkpoint, not silent
 * mass-assignment.
 */
class EmployeeNumberIsImmutableException extends RuntimeException
{
    public function __construct(public readonly string $original, public readonly string $attempted)
    {
        parent::__construct("Employee number '{$original}' is immutable and cannot be changed to '{$attempted}'.");
    }
}
