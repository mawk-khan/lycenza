<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `CompensationService::assign()` targets a salary
 * structure revision that is not `active` (draft or superseded) --
 * the Application-layer clean error ahead of the database trigger
 * `compensation_assignments_require_active_structure`.
 */
class StructureNotActiveException extends PayrollException
{
    public function __construct(public readonly string $structureId, public readonly string $actualStatus)
    {
        parent::__construct(422, 'PAYROLL_STRUCTURE_NOT_ACTIVE', "Salary structure {$structureId} is {$actualStatus}, not active -- it cannot be assigned.");
    }
}
