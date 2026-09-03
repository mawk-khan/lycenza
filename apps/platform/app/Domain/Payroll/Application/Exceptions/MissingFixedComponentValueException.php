<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.3 -- thrown when `CompensationService::assign()` does not
 * receive a value for every `fixed_amount` component of the target
 * structure revision. Discovered while building the Checkpoint 9.3
 * calculation kernel: a fixed component silently defaulting to zero
 * pay at calculation time would be a silent underpayment, not a
 * reasonable default -- every fixed_amount component must have an
 * explicit Employee-specific value the moment compensation is
 * assigned, never resolved later at calculation time.
 */
class MissingFixedComponentValueException extends PayrollException
{
    public function __construct(public readonly string $structureComponentId)
    {
        parent::__construct(422, 'PAYROLL_MISSING_FIXED_COMPONENT_VALUE', "No value was supplied for fixed_amount structure component {$structureComponentId}.");
    }
}
