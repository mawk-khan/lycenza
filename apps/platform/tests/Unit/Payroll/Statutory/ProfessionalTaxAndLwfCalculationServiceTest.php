<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Calculation\LabourWelfareFundRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\LwfCalculationService;
use App\Domain\Payroll\Statutory\Calculation\ProfessionalTaxCalculationService;
use App\Domain\Payroll\Statutory\Calculation\ProfessionalTaxRuleVersion;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6B -- Telangana Professional Tax and Labour Welfare
 * Fund golden fixtures (ADR 0036 correction addendum §1.6/§1.7). Both
 * engines throw until Checkpoint 9.6D.
 */
class ProfessionalTaxAndLwfCalculationServiceTest extends TestCase
{
    private function inr(string $amount): Money
    {
        return Money::of($amount, 'INR');
    }

    #[Test]
    public function pt_15000_is_exempt(): void
    {
        $tax = (new ProfessionalTaxCalculationService)->calculate($this->inr('15000.00'), ProfessionalTaxRuleVersion::telanganaEffectiveApril2026());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function pt_15001_is_150(): void
    {
        $tax = (new ProfessionalTaxCalculationService)->calculate($this->inr('15001.00'), ProfessionalTaxRuleVersion::telanganaEffectiveApril2026());

        $this->assertSame('150.00', $tax->amount());
    }

    #[Test]
    public function pt_20000_is_150(): void
    {
        $tax = (new ProfessionalTaxCalculationService)->calculate($this->inr('20000.00'), ProfessionalTaxRuleVersion::telanganaEffectiveApril2026());

        $this->assertSame('150.00', $tax->amount());
    }

    #[Test]
    public function pt_20001_is_200(): void
    {
        $tax = (new ProfessionalTaxCalculationService)->calculate($this->inr('20001.00'), ProfessionalTaxRuleVersion::telanganaEffectiveApril2026());

        $this->assertSame('200.00', $tax->amount());
    }

    #[Test]
    public function lwf_eligible_employee_first_charge_this_cycle_is_2_and_5(): void
    {
        $result = (new LwfCalculationService)->calculate(
            isEligibleCategory: true,
            alreadyChargedThisCycle: false,
            rule: LabourWelfareFundRuleVersion::telanganaEffectiveApril2026(),
        );

        $this->assertTrue($result->shouldCharge);
        $this->assertSame('2.00', $result->employeeAmount->amount());
        $this->assertSame('5.00', $result->employerAmount->amount());
    }

    #[Test]
    public function lwf_second_charge_attempt_in_the_same_annual_cycle_is_rejected_not_generated(): void
    {
        $result = (new LwfCalculationService)->calculate(
            isEligibleCategory: true,
            alreadyChargedThisCycle: true,
            rule: LabourWelfareFundRuleVersion::telanganaEffectiveApril2026(),
        );

        $this->assertFalse($result->shouldCharge, 'LWF is charged exactly ONCE per statutory annual cycle -- never June + December.');
        $this->assertSame('0.00', $result->employeeAmount->amount());
        $this->assertSame('0.00', $result->employerAmount->amount());
    }

    #[Test]
    public function lwf_legally_excluded_employee_category_never_gets_a_charge(): void
    {
        $result = (new LwfCalculationService)->calculate(
            isEligibleCategory: false,
            alreadyChargedThisCycle: false,
            rule: LabourWelfareFundRuleVersion::telanganaEffectiveApril2026(),
        );

        $this->assertFalse($result->shouldCharge);
        $this->assertSame('0.00', $result->employeeAmount->amount());
        $this->assertSame('0.00', $result->employerAmount->amount());
    }
}
