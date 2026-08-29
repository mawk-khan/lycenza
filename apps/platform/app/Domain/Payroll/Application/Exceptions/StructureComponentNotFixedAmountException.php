<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when a caller supplies an Employee-specific value for a
 * `salary_structure_components` row whose `calculation_type` is
 * `percentage_of_base` -- only `fixed_amount` components ever take an
 * Employee-specific value (ADR 0032 "Where employee-specific
 * compensation values live"); a percentage component is always
 * derived from its base at calculation time. The Application-layer
 * clean error ahead of the database trigger
 * `compensation_assignment_values_require_fixed_component`.
 */
class StructureComponentNotFixedAmountException extends PayrollException
{
    public function __construct(public readonly string $structureComponentId)
    {
        parent::__construct(422, 'PAYROLL_COMPONENT_NOT_FIXED_AMOUNT', "Structure component {$structureComponentId} is not a fixed_amount component -- it cannot take an Employee-specific value.");
    }
}
