<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6B -- `monthlyDeduction` is NEVER negative (ADR 0036
 * correction addendum §1.10) -- an over-withheld situation surfaces as
 * `carryForwardExcess` instead, and an insufficient-available-salary
 * situation surfaces as `residualComplianceException` instead. Never
 * netted into a single signed figure.
 */
final class TdsMonthlyDeductionResult
{
    public function __construct(
        public readonly Money $monthlyDeduction,
        public readonly ?Money $carryForwardExcess = null,
        public readonly ?Money $residualComplianceException = null,
    ) {}
}
