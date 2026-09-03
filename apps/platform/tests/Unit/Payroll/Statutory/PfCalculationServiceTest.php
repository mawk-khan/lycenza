<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Calculation\PfCalculationInput;
use App\Domain\Payroll\Statutory\Calculation\PfCalculationService;
use App\Domain\Payroll\Statutory\Calculation\PfComponentClassification;
use App\Domain\Payroll\Statutory\Calculation\PfEmployeeStatutoryFacts;
use App\Domain\Payroll\Statutory\Calculation\PfRuleVersion;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6B -- the PF golden-fixture legal acceptance contract
 * (ADR 0035 correction addendum §1.1/§1.2). Case IDs PF-01..PF-12
 * match the checkpoint brief exactly, so each failure is directly
 * traceable to its legal source. `PfCalculationService::calculate()`
 * throws until Checkpoint 9.6D implements it against the algorithm
 * already documented on that class -- every test here is expected RED
 * until then.
 *
 * Where the brief specified exact expected rupee figures, they are
 * used verbatim. Where the brief asked this checkpoint to construct
 * and prove a concept (PF-05, PF-09..PF-12), the fixture's own
 * component amounts are chosen so the correct and a naive
 * (hardcoded-five-field) model would disagree, and the expected
 * values are computed directly from the algorithm documented on
 * `PfCalculationService` -- shown inline per case.
 */
class PfCalculationServiceTest extends TestCase
{
    private function rule(): PfRuleVersion
    {
        return PfRuleVersion::effectiveApril2026();
    }

    private function inr(string $amount): Money
    {
        return Money::of($amount, 'INR');
    }

    private function facts(bool $existingMembership, bool $hasUan, bool $higherWageApproved, bool $epsEligible): PfEmployeeStatutoryFacts
    {
        return new PfEmployeeStatutoryFacts($existingMembership, $hasUan, $higherWageApproved, $epsEligible);
    }

    #[Test]
    public function pf_01_existing_member_allowance_share_exactly_50_percent_no_add_back(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('10000.00')->add($this->inr('2000.00')),
                PfComponentClassification::TestedRemuneration->value => $this->inr('12000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('12000.00', $result->uncappedStatutoryWage->amount());
        $this->assertFalse($result->isExcludedEmployee);
        $this->assertSame('12000.00', $result->contributionBase->amount());
        $this->assertSame('1440.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('1440.00', $result->employerTotalContribution->amount());
        $this->assertSame('1000.00', $result->employerEpsContribution->amount());
        $this->assertSame('440.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_02_existing_member_allowance_share_exceeds_50_percent_add_back_applies(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('12000.00')->add($this->inr('2000.00')),
                PfComponentClassification::TestedRemuneration->value => $this->inr('18000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('16000.00', $result->uncappedStatutoryWage->amount());
        $this->assertFalse($result->isExcludedEmployee);
        $this->assertSame('15000.00', $result->contributionBase->amount(), 'Contribution base capped at 15,000 -- no higher-wage approval.');
        $this->assertSame('1800.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('1250.00', $result->employerEpsContribution->amount());
        $this->assertSame('550.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_03_new_employee_uncapped_wage_above_ceiling_no_prior_membership_is_excluded(): void
    {
        // Identical salary shape to PF-02, but a NEW employee with no
        // prior membership and no higher-wage approval. This is the
        // literal anti-pattern ADR 0035 exists to forbid: an
        // implementation that caps first (to 15,000) and then infers
        // membership from the capped figure would wrongly enroll this
        // employee. The correct implementation must determine
        // exclusion from the UNCAPPED 16,000 figure.
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('12000.00')->add($this->inr('2000.00')),
                PfComponentClassification::TestedRemuneration->value => $this->inr('18000.00'),
            ],
            facts: $this->facts(false, false, false, false),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('16000.00', $result->uncappedStatutoryWage->amount());
        $this->assertTrue($result->isExcludedEmployee);
        $this->assertSame('0.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('0.00', $result->employerTotalContribution->amount());
        $this->assertSame('0.00', $result->employerEpsContribution->amount());
        $this->assertSame('0.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_04_existing_member_pf_wage_14000_whole_inr_rounding(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('14000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('1680.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('1680.00', $result->employerTotalContribution->amount());
        // 14000 * 8.33% = 1166.20 -- half-up rounds DOWN to 1166.
        $this->assertSame('1166.00', $result->employerEpsContribution->amount());
        $this->assertSame('514.00', $result->employerEpfContribution->amount(), 'employer EPF = employer total (1680) - EPS (1166).');
    }

    #[Test]
    public function pf_05_overtime_must_be_excluded_from_both_pf_wage_and_the_50_percent_test(): void
    {
        // Core 8,000; HRA (tested) 9,000; Overtime (excluded, never
        // remuneration) 5,000. Correct model: remuneration = 8,000 +
        // 9,000 = 17,000; 50% = 8,500; 9,000 > 8,500 -> add-back 500;
        // uncapped PF wage = 8,500.
        //
        // A naive five-field model that dumps Overtime into a generic
        // "Special"/tested bucket instead of excluding it entirely
        // would compute remuneration = 8,000 + 14,000 = 22,000, 50% =
        // 11,000, tested (14,000) > 11,000, add-back 3,000, uncapped
        // PF wage = 11,000 -- a materially different (and wrong)
        // figure. This fixture's numbers are deliberately chosen so
        // the two models disagree, proving the classification model
        // is load-bearing, not cosmetic.
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('8000.00'),
                PfComponentClassification::TestedRemuneration->value => $this->inr('9000.00'),
                PfComponentClassification::ExcludedNonRemuneration->value => $this->inr('5000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('8500.00', $result->uncappedStatutoryWage->amount(), 'Overtime must never enter the 50%-test remuneration base.');
        $this->assertSame('1020.00', $result->employeeMandatoryContribution->amount());
        // 8500 * 8.33% = 708.05 -- half-up rounds DOWN to 708.
        $this->assertSame('708.00', $result->employerEpsContribution->amount());
        $this->assertSame('312.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_06_existing_uan_member_above_ceiling_without_higher_wage_approval_stays_capped(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('42000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('42000.00', $result->uncappedStatutoryWage->amount());
        $this->assertFalse($result->isExcludedEmployee);
        $this->assertSame('15000.00', $result->contributionBase->amount(), 'Existing UAN/membership never authorizes contribution above 15,000 without explicit higher-wage approval.');
        $this->assertSame('1800.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('1250.00', $result->employerEpsContribution->amount());
        $this->assertSame('550.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_07_existing_eps_eligible_member_with_approved_higher_wage_contribution(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('42000.00'),
            ],
            facts: $this->facts(true, true, true, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('42000.00', $result->contributionBase->amount(), 'Approved higher-wage contribution uses the uncapped wage as the base.');
        $this->assertSame('5040.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('5040.00', $result->employerTotalContribution->amount());
        $this->assertSame('1250.00', $result->employerEpsContribution->amount(), 'EPS itself always stays wage-ceiling-capped regardless of the higher contribution base.');
        $this->assertSame('3790.00', $result->employerEpfContribution->amount());
        $this->assertSame('75.00', $result->edliContribution->amount(), 'EDLI stays capped at the 15,000 EDLI wage ceiling.');
        $this->assertSame('500.00', $result->adminCharge->amount(), 'Admin charge floor applies once the rate-based figure (75) is below the statutory minimum (500).');
    }

    #[Test]
    public function pf_08_fresh_above_ceiling_member_approved_higher_wage_but_not_eps_eligible(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('42000.00'),
            ],
            facts: $this->facts(false, false, true, false),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertFalse($result->isExcludedEmployee, 'Approved higher-wage contribution is itself an enrollment decision, even for a fresh employee.');
        $this->assertSame('5040.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('0.00', $result->employerEpsContribution->amount(), 'EPS ineligibility must never be inferred from UAN/higher-wage approval.');
        $this->assertSame('5040.00', $result->employerEpfContribution->amount(), 'The entire employer share flows to EPF when EPS does not apply.');
    }

    #[Test]
    public function pf_09_voluntary_provident_fund_never_changes_the_employer_contribution(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('10000.00'),
                PfComponentClassification::TestedRemuneration->value => $this->inr('2000.00'),
            ],
            facts: $this->facts(true, true, false, true),
            voluntaryEmployeeContribution: $this->inr('2000.00'),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('1200.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('2000.00', $result->employeeVoluntaryContribution->amount());
        $this->assertSame('1200.00', $result->employerTotalContribution->amount(), 'VPF must never inflate the employer contribution.');
        $this->assertSame('833.00', $result->employerEpsContribution->amount());
        $this->assertSame('367.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_10_rounding_boundary_14999(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('14999.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        // 14999 * 12% = 1799.88 -- half-up rounds UP to 1800.
        $this->assertSame('1800.00', $result->employeeMandatoryContribution->amount());
        $this->assertSame('1800.00', $result->employerTotalContribution->amount());
        // 14999 * 8.33% = 1249.4167 -- half-up rounds DOWN to 1249.
        $this->assertSame('1249.00', $result->employerEpsContribution->amount());
        $this->assertSame('551.00', $result->employerEpfContribution->amount());
    }

    #[Test]
    public function pf_11_admin_charge_and_edli_stay_wage_ceiling_capped_even_far_above_it(): void
    {
        $input = new PfCalculationInput(
            componentAmounts: [
                PfComponentClassification::CoreWage->value => $this->inr('20000.00'),
            ],
            facts: $this->facts(true, true, false, true),
        );

        $result = (new PfCalculationService)->calculate($input, $this->rule());

        $this->assertSame('15000.00', $result->contributionBase->amount());
        $this->assertSame('75.00', $result->edliContribution->amount(), '15,000 EDLI-ceiling-capped wage * 0.5%.');
        $this->assertSame('500.00', $result->adminCharge->amount(), 'Statutory minimum floor applies.');
    }

    #[Test]
    public function pf_12_historical_arrear_calculations_stay_independent_per_period_never_collapsed(): void
    {
        // A July correction affecting April and May must never merge
        // into one combined statutory record -- proven here at the
        // pure-engine layer by showing two independent calculate()
        // calls (one per corrected period) never share state and each
        // reflects only its own period's wage. Which historical month
        // a resulting record is TAGGED to is an Application-layer
        // (Checkpoint 9.6C/9.6D) concern, proven there; this test
        // proves the engine itself has no hidden cross-call state that
        // could cause a collapse.
        $service = new PfCalculationService;
        $facts = $this->facts(true, true, false, true);

        $april = $service->calculate(new PfCalculationInput(
            componentAmounts: [PfComponentClassification::CoreWage->value => $this->inr('10000.00')],
            facts: $facts,
        ), $this->rule());

        $may = $service->calculate(new PfCalculationInput(
            componentAmounts: [PfComponentClassification::CoreWage->value => $this->inr('11000.00')],
            facts: $facts,
        ), $this->rule());

        $this->assertSame('1200.00', $april->employeeMandatoryContribution->amount());
        $this->assertSame('1320.00', $may->employeeMandatoryContribution->amount());
        $this->assertNotSame($april->employeeMandatoryContribution->amount(), $may->employeeMandatoryContribution->amount());
    }
}
