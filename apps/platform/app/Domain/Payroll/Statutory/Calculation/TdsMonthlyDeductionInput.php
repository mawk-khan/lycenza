<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6B -- one payroll cycle's TDS spreading input. Never
 * carries a "this month's raw salary %" concept -- only the annual
 * projection, what has already been withheld/credited, and how many
 * cycles remain (ADR 0035 correction addendum's annualized-projection
 * principle, Section 392).
 */
final class TdsMonthlyDeductionInput
{
    public function __construct(
        public readonly Money $annualProjectedLiability,
        public readonly Money $cumulativeAlreadyDeducted,
        public readonly int $remainingCycles,
        public readonly ?Money $priorEmployerTdsCredit = null,
        /**
         * The salary legally available for TDS withholding this
         * cycle, after every other mandatory deduction -- required
         * only for the final/reconciliation cycle where an
         * insufficient-salary fail-closed decision might apply (ADR
         * 0035 correction addendum §1.10). Null when not relevant.
         */
        public readonly ?Money $availableSalaryForWithholding = null,
    ) {}
}
