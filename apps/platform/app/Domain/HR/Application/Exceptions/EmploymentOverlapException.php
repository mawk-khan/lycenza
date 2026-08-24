<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\EmploymentService::create() is
 * asked to create an EmploymentRecord whose `[starts_on, ends_on-or-
 * open]` interval overlaps an existing EmploymentRecord for the same
 * Employee (docs/modules/HR.md "Temporal data strategy" -- rehire must
 * be sequential: Employment #1 ends, Employment #2 begins later).
 */
class EmploymentOverlapException extends RuntimeException
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $conflictingEmploymentRecordId,
    ) {
        parent::__construct("Employee {$employeeId} already has an overlapping EmploymentRecord ({$conflictingEmploymentRecordId}).");
    }
}
