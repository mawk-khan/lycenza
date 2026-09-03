<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.5 correction (ADR 0034 "Run kinds, correction model, and
 * posting") -- one `correction_delta`-mode `payroll_adjustments` row,
 * translated into the plain shape
 * `PayrollCalculationEngine::calculateFromCorrectionDeltas()` needs --
 * deliberately framework-light (no Eloquent model), mirroring
 * `ManualOverrideLineInput`'s identical rationale. Unlike
 * `ManualOverrideLineInput`, this DTO carries an explicit `effect`
 * (increase|decrease): a correction's whole purpose is representing a
 * SIGNED delta, never an absolute replacement value.
 */
final class CorrectionDeltaLineInput
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $amount,
        public readonly string $effect,
        public readonly bool $isEarning,
        public readonly ?string $resolvedLedgerAccountId,
    ) {}
}
