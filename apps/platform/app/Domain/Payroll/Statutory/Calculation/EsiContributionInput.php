<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6B -- one EmploymentRecord's ESI contribution input for
 * one payroll cycle. `coveredForThisPeriod` is an already-resolved
 * fact (from `EsiCoverageDeterminationService`, applied once at
 * period start and carried for the whole contribution period, ADR
 * 0035 correction addendum §1.8) -- this class never re-derives it.
 * `statutoryWage` is the component-classified ESI wage for the actual
 * cycle (partial month or full), never legacy raw gross cash salary.
 */
final class EsiContributionInput
{
    public function __construct(
        public readonly bool $coveredForThisPeriod,
        public readonly Money $statutoryWage,
        public readonly ?Money $averageDailyWage = null,
    ) {}
}
