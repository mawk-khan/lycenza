<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Calculation\TdsMonthlyDeductionInput;
use App\Domain\Payroll\Statutory\Calculation\TdsMonthlyDeductionService;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6B -- TDS monthly-spreading golden fixtures (ADR 0035
 * correction addendum §1.10, Section 392's annualized principle).
 * Case IDs TDS-13..TDS-19. `TdsMonthlyDeductionService` throws until
 * Checkpoint 9.6E.
 */
class TdsMonthlyDeductionServiceTest extends TestCase
{
    private function inr(string $amount): Money
    {
        return Money::of($amount, 'INR');
    }

    #[Test]
    public function tds_13_annual_liability_124800_deducted_31200_nine_months_remaining(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('124800.00'),
            cumulativeAlreadyDeducted: $this->inr('31200.00'),
            remainingCycles: 9,
        ));

        $this->assertSame('10400.00', $result->monthlyDeduction->amount());
        $this->assertNull($result->carryForwardExcess);
        $this->assertNull($result->residualComplianceException);
    }

    #[Test]
    public function tds_14_mid_year_revision_raises_projection_to_78000_six_cycles_remaining(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('78000.00'),
            cumulativeAlreadyDeducted: $this->inr('0.00'),
            remainingCycles: 6,
        ));

        $this->assertSame('13000.00', $result->monthlyDeduction->amount());
    }

    #[Test]
    public function tds_15_prior_employer_tds_credit_reduces_remaining_liability(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('120000.00'),
            cumulativeAlreadyDeducted: $this->inr('0.00'),
            remainingCycles: 10,
            priorEmployerTdsCredit: $this->inr('20000.00'),
        ));

        $this->assertSame('10000.00', $result->monthlyDeduction->amount());
    }

    #[Test]
    public function tds_16_projected_liability_below_already_withheld_never_goes_negative(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('80000.00'),
            cumulativeAlreadyDeducted: $this->inr('90000.00'),
            remainingCycles: 4,
        ));

        $this->assertSame('0.00', $result->monthlyDeduction->amount());
        $this->assertNotNull($result->carryForwardExcess);
        $this->assertSame('10000.00', $result->carryForwardExcess->amount(), 'The Rs 10,000 over-withholding is carried into remaining-year adjustment logic, never a negative current-cycle deduction.');
    }

    #[Test]
    public function tds_17_march_residual_tax_with_enough_available_salary_deducts_the_exact_residual(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('8000.00'),
            cumulativeAlreadyDeducted: $this->inr('0.00'),
            remainingCycles: 1,
            availableSalaryForWithholding: $this->inr('50000.00'),
        ));

        $this->assertSame('8000.00', $result->monthlyDeduction->amount());
        $this->assertNull($result->residualComplianceException);
    }

    #[Test]
    public function tds_18_march_residual_tax_exceeds_legally_available_salary_fails_closed(): void
    {
        $result = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('15000.00'),
            cumulativeAlreadyDeducted: $this->inr('0.00'),
            remainingCycles: 1,
            availableSalaryForWithholding: $this->inr('10000.00'),
        ));

        $this->assertSame('10000.00', $result->monthlyDeduction->amount(), 'Withhold only the legally available amount -- net pay must never go negative.');
        $this->assertNotNull($result->residualComplianceException);
        $this->assertSame('5000.00', $result->residualComplianceException->amount(), 'The unresolved Rs 5,000 becomes a typed statutory compliance exception, never a fabricated employer-funded payment.');
    }

    #[Test]
    public function tds_19_approved_school_policy_regime_switch_reprojects_future_only(): void
    {
        // "Before switch": locked-in historical projection.
        $beforeSwitch = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('100000.00'),
            cumulativeAlreadyDeducted: $this->inr('50000.00'),
            remainingCycles: 5,
        ));
        $this->assertSame('10000.00', $beforeSwitch->monthlyDeduction->amount());

        // "After switch": a genuinely separate call with a revised
        // annual figure (the new regime's own projection) but the
        // SAME already-withheld amount, since prior payroll history
        // is immutable -- proven here as engine statelessness; the
        // actual immutability guarantee for a posted payroll run is
        // Checkpoint 9.4's existing freeze discipline, exercised at
        // the Application layer in Checkpoint 9.6E/9.6C, not here.
        $afterSwitch = (new TdsMonthlyDeductionService)->calculate(new TdsMonthlyDeductionInput(
            annualProjectedLiability: $this->inr('60000.00'),
            cumulativeAlreadyDeducted: $this->inr('50000.00'),
            remainingCycles: 5,
        ));

        $this->assertSame('2000.00', $afterSwitch->monthlyDeduction->amount());
        $this->assertSame('10000.00', $beforeSwitch->monthlyDeduction->amount(), 'The historical calculation result must remain exactly as it was -- the engine call for "after" never mutates "before".');
    }
}
