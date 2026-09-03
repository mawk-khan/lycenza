<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.3 -- one computed earning/deduction line, produced by
 * `PayrollCalculationEngine`, not yet persisted. `amount` is always
 * non-negative; `effect` (increase|decrease) plus the owning
 * component's `isEarning` flag together determine posting direction
 * later (Checkpoint 9.5, ADR 0034 "Deduction accounting").
 */
final class CalculatedResultLine
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $amount,
        public readonly string $effect,
        public readonly bool $isEarning,
        public readonly ?string $resolvedLedgerAccountId,
    ) {}
}
