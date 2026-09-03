<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Calculation\EsiContributionCalculationService;
use App\Domain\Payroll\Statutory\Calculation\EsiContributionInput;
use App\Domain\Payroll\Statutory\Calculation\EsiCoverageDeterminationService;
use App\Domain\Payroll\Statutory\Calculation\EsiRuleVersion;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6B -- the ESI golden-fixture legal acceptance contract
 * (ADR 0035 correction addendum §1.8/§1.9). Case IDs ESI-01..ESI-12
 * match the checkpoint brief. Both engine classes throw until
 * Checkpoint 9.6D -- every test here is expected RED until then.
 *
 * Rounding throughout is UPWARD to the next whole rupee (ceiling),
 * never half-up -- deliberately different from PF's rounding rule,
 * see `EsiContributionCalculationService`'s own docblock.
 */
class EsiCalculationServiceTest extends TestCase
{
    private function rule(): EsiRuleVersion
    {
        return EsiRuleVersion::effectiveApril2026();
    }

    private function inr(string $amount): Money
    {
        return Money::of($amount, 'INR');
    }

    #[Test]
    public function esi_01_entry_wage_exactly_21000_is_covered(): void
    {
        $covered = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('21000.00'), $this->rule());

        $this->assertTrue($covered);
    }

    #[Test]
    public function esi_02_entry_wage_21001_not_previously_continuing_is_not_covered(): void
    {
        $covered = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('21001.00'), $this->rule());

        $this->assertFalse($covered);
    }

    #[Test]
    public function esi_03_covered_at_period_start_continues_after_crossing_the_ceiling_mid_period(): void
    {
        // April entry wage 20,500 established coverage for Apr-Sep;
        // July wage rises to 22,000 -- continuity keeps coverage on
        // for the rest of that same contribution period.
        $coveredAtEntry = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('20500.00'), $this->rule());
        $this->assertTrue($coveredAtEntry);

        $july = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: $coveredAtEntry, statutoryWage: $this->inr('22000.00')),
            $this->rule(),
        );

        $this->assertTrue($july->isCovered);
        $this->assertSame('165.00', $july->employeeContribution->amount());
        $this->assertSame('715.00', $july->employerContribution->amount());
    }

    #[Test]
    public function esi_04_new_period_entry_at_22000_general_coverage_turns_off(): void
    {
        $coveredAtNewPeriodStart = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('22000.00'), $this->rule());
        $this->assertFalse($coveredAtNewPeriodStart);

        $october = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: $coveredAtNewPeriodStart, statutoryWage: $this->inr('22000.00')),
            $this->rule(),
        );

        $this->assertFalse($october->isCovered);
        $this->assertSame('0.00', $october->employeeContribution->amount());
        $this->assertSame('0.00', $october->employerContribution->amount());
    }

    #[Test]
    public function esi_05_21000_boundary_is_covered(): void
    {
        $covered = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('21000.00'), $this->rule());

        $this->assertTrue($covered);
    }

    #[Test]
    public function esi_06_average_daily_wage_exactly_176_exempts_the_employee_only(): void
    {
        $result = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: true, statutoryWage: $this->inr('4600.00'), averageDailyWage: $this->inr('176.00')),
            $this->rule(),
        );

        $this->assertSame('0.00', $result->employeeContribution->amount());
        // 4600 * 3.25% = 149.50 -- ceiling to next rupee is still 150.
        $this->assertSame('150.00', $result->employerContribution->amount(), 'Employer contribution is never exempted by the average-daily-wage rule.');
    }

    #[Test]
    public function esi_07_average_daily_wage_176_01_the_employee_exemption_no_longer_applies(): void
    {
        $result = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: true, statutoryWage: $this->inr('4600.00'), averageDailyWage: $this->inr('176.01')),
            $this->rule(),
        );

        // 4600 * 0.75% = 34.50 -- ceiling to next rupee is 35.
        $this->assertSame('35.00', $result->employeeContribution->amount());
        $this->assertSame('150.00', $result->employerContribution->amount());
    }

    #[Test]
    public function esi_08_statutory_wage_18950_upward_rounding_both_sides(): void
    {
        $result = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: true, statutoryWage: $this->inr('18950.00')),
            $this->rule(),
        );

        // 18950 * 0.75% = 142.125 -- ceiling to 143.
        $this->assertSame('143.00', $result->employeeContribution->amount());
        // 18950 * 3.25% = 615.875 -- ceiling to 616.
        $this->assertSame('616.00', $result->employerContribution->amount());
    }

    #[Test]
    public function esi_09_partial_month_uses_actual_statutory_wage_earned_no_threshold_proration(): void
    {
        // Coverage was already established earlier in the period
        // (full-wage entry); this partial month's ACTUAL earnings are
        // 12,000 -- the 21,000 threshold is never artificially
        // pro-rated down for a partial month, contribution is simply
        // computed off the real amount earned.
        $result = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: true, statutoryWage: $this->inr('12000.00')),
            $this->rule(),
        );

        $this->assertSame('90.00', $result->employeeContribution->amount());
        $this->assertSame('390.00', $result->employerContribution->amount());
    }

    #[Test]
    public function esi_10_calculation_uses_the_statutory_esi_wage_classification_never_raw_cash_gross(): void
    {
        // Cash gross salary might be 25,000 (e.g. it includes a
        // component classified as excluded from ESI wage entirely,
        // Checkpoint 9.6C) -- `EsiContributionInput` structurally has
        // no "cash gross" field at all, only `statutoryWage`,
        // enforcing at the API-design level that a caller must supply
        // the already-classified statutory figure (19,000), never the
        // raw gross (25,000).
        $result = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: true, statutoryWage: $this->inr('19000.00')),
            $this->rule(),
        );

        // 19000 * 0.75% = 142.50 -- ceiling to 143.
        $this->assertSame('143.00', $result->employeeContribution->amount());
        // 19000 * 3.25% = 617.50 -- ceiling to 618.
        $this->assertSame('618.00', $result->employerContribution->amount());
    }

    #[Test]
    public function esi_11_historical_wage_correction_inside_the_contribution_period_preserves_continuity(): void
    {
        // Coverage was established from April's ORIGINAL entry wage
        // (20,000, covered). A later correction raises April's actual
        // wage to 20,800 (still under the ceiling, but the point is
        // structural): the correction recomputes the CONTRIBUTION off
        // the corrected wage while reusing the SAME, already-decided
        // coverage fact -- it never re-runs coverage determination
        // against the corrected figure.
        $coveredAtOriginalEntry = (new EsiCoverageDeterminationService)->determineCoverageAtPeriodStart($this->inr('20000.00'), $this->rule());
        $this->assertTrue($coveredAtOriginalEntry);

        $corrected = (new EsiContributionCalculationService)->calculate(
            new EsiContributionInput(coveredForThisPeriod: $coveredAtOriginalEntry, statutoryWage: $this->inr('20800.00')),
            $this->rule(),
        );

        $this->assertTrue($corrected->isCovered);
        $this->assertSame('156.00', $corrected->employeeContribution->amount());
        $this->assertSame('676.00', $corrected->employerContribution->amount());
    }

    #[Test]
    public function esi_12_disability_special_threshold_is_legally_deferred(): void
    {
        $this->markTestSkipped('ESI-12: disability special threshold (Rs 25,000) is DEFERRED -- ADDITIONAL LEGAL CLARIFICATION REQUIRED (ADR 0035 correction addendum §1.9). No expected value is invented.');
    }
}
