<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.2 -- one Employee-specific fixed-amount value supplied to
 * `CompensationService::assign()`. Deliberately carries only a
 * `salaryStructureComponentId` and `amount` -- the amount's Highly
 * Sensitive classification (ADR 0032 "Sensitive values") means this
 * class exists so the service boundary cannot accept an unbounded
 * array that might carry unrelated fields into a sensitive-value code
 * path.
 */
final class FixedComponentValueInput
{
    public function __construct(
        public readonly string $salaryStructureComponentId,
        public readonly string $amount,
    ) {}
}
