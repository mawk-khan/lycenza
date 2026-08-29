<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.3 -- one `manual_override`-mode `payroll_adjustments` row,
 * translated into the plain shape `PayrollCalculationEngine::calculateFromManualOverrides()`
 * needs -- deliberately framework-light (no Eloquent model) so the
 * engine stays a pure, DB-free calculator (golden-fixture testable in
 * isolation).
 */
final class ManualOverrideLineInput
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $amount,
        public readonly bool $isEarning,
        public readonly ?string $resolvedLedgerAccountId,
    ) {}
}
