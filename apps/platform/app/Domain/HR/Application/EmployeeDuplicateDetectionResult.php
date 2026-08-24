<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.12 -- the outcome of `EmployeeDuplicateDetector::detect()`.
 * `status` is `none`/`exact`/`potential`. Directory-tier fields only
 * (checkpoint brief section 35) -- never personal email/phone/DOB/
 * address, regardless of the caller's own capabilities, since the
 * detector itself has no capability context to reason about (the
 * caller, `EmployeeImportService`, has already authorized the actor
 * for `hr.employees.manage` before this ever runs).
 */
final class EmployeeDuplicateDetectionResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $matchedEmployeeId,
        public readonly ?string $matchedEmployeeNumber,
        public readonly int $potentialCandidateCount,
    ) {}

    public static function none(): self
    {
        return new self('none', null, null, 0);
    }

    public static function exact(string $employeeId, string $employeeNumber): self
    {
        return new self('exact', $employeeId, $employeeNumber, 1);
    }

    public static function potential(int $candidateCount): self
    {
        return new self('potential', null, null, $candidateCount);
    }
}
