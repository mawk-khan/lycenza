<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when a mutation that requires `draft` status (adding a
 * component, editing one) targets a `salary_structures` row that has
 * already left `draft` -- the Application-layer pre-check clean error
 * ahead of the database trigger `salary_structure_components_reject_after_draft`,
 * which remains the authoritative guarantee (ADR 0032).
 */
class StructureNotDraftException extends PayrollException
{
    public function __construct(
        public readonly string $structureId,
        public readonly string $actualStatus,
    ) {
        parent::__construct(422, 'PAYROLL_STRUCTURE_NOT_DRAFT', "Salary structure {$structureId} is {$actualStatus}, not draft -- it can no longer be edited.");
    }
}
