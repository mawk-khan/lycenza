<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.3 -- the output of `PayrollCalculationEngine`, not yet
 * persisted. `netAmount` may be negative here (the engine is a pure
 * calculator); `PayrollRunService` is what rejects a negative net pay
 * for a regular run before ever writing it (ADR 0034 Mandatory
 * Decision #13).
 */
final class CalculatedResult
{
    /**
     * @param  list<CalculatedResultLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $grossAmount,
        public readonly string $totalDeductions,
        public readonly string $netAmount,
    ) {}
}
