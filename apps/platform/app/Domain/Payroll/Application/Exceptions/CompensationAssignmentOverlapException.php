<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `CompensationService::assign()` is asked to create an
 * `employee_compensation_assignments` row whose effective range
 * overlaps an existing one for the same EmploymentRecord -- the
 * Application-layer clean error ahead of the database trigger
 * `compensation_assignments_reject_overlap`, which remains the
 * authoritative, concurrency-safe guarantee (ADR 0034 -- no
 * `EXCLUDE USING gist`, matching `employment_records`' own precedent).
 */
class CompensationAssignmentOverlapException extends PayrollException
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly ?string $conflictingAssignmentId = null,
    ) {
        $suffix = $conflictingAssignmentId !== null ? " ({$conflictingAssignmentId})" : '';
        parent::__construct(422, 'PAYROLL_COMPENSATION_OVERLAP', "EmploymentRecord {$employmentRecordId} already has an overlapping compensation assignment{$suffix}.");
    }
}
