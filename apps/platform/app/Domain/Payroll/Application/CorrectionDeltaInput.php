<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.5 correction -- the plain input to
 * `PayrollRunService::recordCorrectionDelta()`: a signed delta against
 * one salary component, mirroring `recordManualOverride()`'s
 * `array<string, string>` convenience but as a typed DTO, since a
 * correction line needs BOTH an amount AND an explicit direction
 * (`effect`: increase|decrease) -- a bare `componentId => amount` map
 * cannot express that second axis.
 */
final class CorrectionDeltaInput
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $amount,
        public readonly string $effect,
    ) {}
}
