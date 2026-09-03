<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when a genuine concurrent race loses against
 * `salary_structures_one_active_per_code`'s partial unique index --
 * mirrors App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException's
 * exact role for AcademicYear activation. The sequential pre-check in
 * SalaryStructureService::activate() (locking the current active
 * revision, if any) makes this the rare, real-concurrency-only path,
 * not the ordinary one.
 */
class ConcurrentStructureActivationConflictException extends PayrollException
{
    public function __construct(public readonly string $structureId)
    {
        parent::__construct(409, 'PAYROLL_CONCURRENT_STRUCTURE_ACTIVATION', "Salary structure {$structureId} could not be activated: another revision of the same code was activated concurrently.");
    }
}
