<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6B -- `PfCalculationService::calculate()`'s frozen
 * output shape. `uncappedStatutoryWage` is retained on the result
 * (never dropped after membership determination) precisely so a
 * caller/test can prove membership was decided from it, not from
 * `contributionBase` -- ADR 0036 correction addendum §1.1's central
 * anti-pattern this DTO exists to make impossible to hide.
 */
final class PfCalculationResult
{
    public function __construct(
        public readonly Money $uncappedStatutoryWage,
        public readonly bool $isExcludedEmployee,
        public readonly Money $contributionBase,
        public readonly Money $employeeMandatoryContribution,
        public readonly Money $employeeVoluntaryContribution,
        public readonly Money $employerTotalContribution,
        public readonly Money $employerEpsContribution,
        public readonly Money $employerEpfContribution,
        public readonly Money $edliContribution,
        public readonly Money $adminCharge,
    ) {}
}
