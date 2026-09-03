<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's TDS-13..
 * TDS-19 golden fixtures.
 */
class TdsMonthlyDeductionService
{
    /**
     * ADR 0035 correction addendum §1.10 -- the algorithm:
     *   1. remainingLiability = annualProjectedLiability
     *        - cumulativeAlreadyDeducted - (priorEmployerTdsCredit ?? 0)
     *   2. if remainingLiability <= 0:
     *        monthlyDeduction = 0
     *        carryForwardExcess = remainingLiability < 0 ? abs(remainingLiability) : null
     *        done.
     *   3. rawMonthly = round_half_up_whole_rupee(remainingLiability / remainingCycles)
     *   4. if availableSalaryForWithholding is set AND rawMonthly > availableSalaryForWithholding:
     *        monthlyDeduction = availableSalaryForWithholding
     *        residualComplianceException = rawMonthly - availableSalaryForWithholding
     *        -- FAIL CLOSED: never create negative net pay, never
     *        fabricate an employer-funded TDS payment, surface the
     *        unresolved amount instead.
     *      else:
     *        monthlyDeduction = rawMonthly
     */
    public function calculate(TdsMonthlyDeductionInput $input): TdsMonthlyDeductionResult
    {
        $currency = $input->annualProjectedLiability->currency();
        $zero = Money::of('0.00', $currency);

        $priorCredit = $input->priorEmployerTdsCredit ?? $zero;
        $remainingLiability = $input->annualProjectedLiability
            ->add($input->cumulativeAlreadyDeducted->negated())
            ->add($priorCredit->negated());

        if (! $remainingLiability->isPositive()) {
            return new TdsMonthlyDeductionResult(
                monthlyDeduction: $zero,
                carryForwardExcess: $remainingLiability->isNegative() ? $remainingLiability->negated() : null,
            );
        }

        $rawMonthly = $remainingLiability
            ->multiplyByRate(bcdiv('1', (string) $input->remainingCycles, 20), 0)
            ->multiplyByRate('1.00', 2);

        if ($input->availableSalaryForWithholding !== null
            && $rawMonthly->add($input->availableSalaryForWithholding->negated())->isPositive()) {
            return new TdsMonthlyDeductionResult(
                monthlyDeduction: $input->availableSalaryForWithholding,
                residualComplianceException: $rawMonthly->add($input->availableSalaryForWithholding->negated()),
            );
        }

        return new TdsMonthlyDeductionResult(monthlyDeduction: $rawMonthly);
    }
}
