<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A closure correction (item 10, closure hardening) -- surfaced
 * when the database's own partial unique index
 * (`employee_assignments_one_primary_open_per_employment`) rejects a
 * concurrent `setPrimary()` call that lost the race -- this is the
 * loser's transaction being told "another Assignment under this
 * EmploymentRecord was already promoted to primary concurrently,"
 * never a silent double-primary state. Mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException's
 * identical shape and reasoning (409, retry-safe), proven for real by
 * PrimaryAssignmentConcurrencyTest using two genuinely separate OS
 * processes -- not a sequential simulation.
 */
class ConcurrentPrimaryAssignmentConflictException extends HrException
{
    public function __construct(public readonly string $employmentRecordId)
    {
        parent::__construct(
            409,
            'HR_PRIMARY_ASSIGNMENT_CONFLICT',
            "Another Assignment under EmploymentRecord {$employmentRecordId} was promoted to primary concurrently. Refresh and try again.",
        );
    }
}
