<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Calculation\IncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\IncomeTaxSlabCalculator;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checkpoint 9.6B -- annual income-tax golden fixtures under the
 * Income-tax Act, 2025 / Section 392 (ADR 0035 correction addendum
 * §1.3/§1.5). Case IDs TDS-01..TDS-12/TDS-20 (the annual-liability
 * subset; TDS-13..TDS-19's monthly-spreading fixtures live in
 * `TdsMonthlyDeductionServiceTest`). `IncomeTaxSlabCalculator` throws
 * until Checkpoint 9.6E.
 */
class IncomeTaxSlabCalculatorTest extends TestCase
{
    private function inr(string $amount): Money
    {
        return Money::of($amount, 'INR');
    }

    private function newRegime(): IncomeTaxRuleVersion
    {
        return IncomeTaxRuleVersion::newRegimeEffectiveApril2026();
    }

    private function oldRegime(): IncomeTaxRuleVersion
    {
        return IncomeTaxRuleVersion::oldRegimeEffectiveApril2026();
    }

    #[Test]
    public function tds_01_taxable_4l_is_nil(): void
    {
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('400000.00'), $this->newRegime());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function tds_02_taxable_12l_rebate_to_zero(): void
    {
        // Pre-rebate slab tax = 20,000 (4-8L @5%) + 40,000 (8-12L
        // @10%) = 60,000. Rebate = min(60,000, 60,000) = 60,000.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('1200000.00'), $this->newRegime());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function tds_03_taxable_12_10l_marginal_relief(): void
    {
        // Slab tax = 60,000 + 15% * 10,000 = 61,500. Income excess
        // over the 12L threshold = 10,000. 61,500 > 10,000, so
        // marginal relief caps tax at 10,000. + 4% cess (400) = 10,400.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('1210000.00'), $this->newRegime());

        $this->assertSame('10400.00', $tax->amount());
    }

    #[Test]
    public function tds_04_taxable_13l_slab_tax_plus_cess(): void
    {
        // Slab tax = 60,000 + 15% * 100,000 = 75,000. No rebate, no
        // relief needed (75,000 < income excess of 100,000). + 4%
        // cess (3,000) = 78,000.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('1300000.00'), $this->newRegime());

        $this->assertSame('78000.00', $tax->amount());
    }

    #[Test]
    public function tds_05_taxable_16l(): void
    {
        // Slab tax = 60,000 + 15% * 400,000 = 120,000. + 4% cess
        // (4,800) = 124,800.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('1600000.00'), $this->newRegime());

        $this->assertSame('124800.00', $tax->amount());
    }

    #[Test]
    public function tds_06_taxable_24l(): void
    {
        // Slab tax = 120,000 + 20% * 400,000 + 25% * 400,000 =
        // 120,000 + 80,000 + 100,000 = 300,000. + 4% cess (12,000) =
        // 312,000.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('2400000.00'), $this->newRegime());

        $this->assertSame('312000.00', $tax->amount());
    }

    #[Test]
    public function tds_07_taxable_30l(): void
    {
        // Slab tax = 300,000 + 30% * 600,000 = 480,000. + 4% cess
        // (19,200) = 499,200.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('3000000.00'), $this->newRegime());

        $this->assertSame('499200.00', $tax->amount());
    }

    #[Test]
    public function tds_08_old_regime_taxable_5l_rebate_to_zero(): void
    {
        // Slab tax = 5% * 250,000 = 12,500. Rebate = min(12,500,
        // 12,500) = 12,500.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('500000.00'), $this->oldRegime());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function tds_09_old_regime_taxable_5_10l_marginal_relief(): void
    {
        // Slab tax = 12,500 + 20% * 10,000 = 14,500. Income excess
        // over the 5L threshold = 10,000. Relief caps tax at 10,000.
        // + 4% cess (400) = 10,400.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('510000.00'), $this->oldRegime());

        $this->assertSame('10400.00', $tax->amount());
    }

    #[Test]
    public function tds_10_old_regime_taxable_12l(): void
    {
        // Slab tax = 12,500 + 20% * 500,000 + 30% * 200,000 =
        // 12,500 + 100,000 + 60,000 = 172,500. + 4% cess (6,900) =
        // 179,400.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('1200000.00'), $this->oldRegime());

        $this->assertSame('179400.00', $tax->amount());
    }

    #[Test]
    public function tds_11_new_regime_salary_12_75l_standard_deduction_rebate_to_zero(): void
    {
        $taxableIncome = $this->inr('1275000.00')->add($this->inr('75000.00')->negated());
        $this->assertSame('1200000.00', $taxableIncome->amount());

        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($taxableIncome, $this->newRegime());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function tds_12_old_regime_salary_5_50l_standard_deduction_rebate_to_zero(): void
    {
        $taxableIncome = $this->inr('550000.00')->add($this->inr('50000.00')->negated());
        $this->assertSame('500000.00', $taxableIncome->amount());

        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($taxableIncome, $this->oldRegime());

        $this->assertSame('0.00', $tax->amount());
    }

    #[Test]
    public function tds_20_surcharge_marginal_relief_boundary_is_table_driven_never_hardcoded(): void
    {
        // Taxable income Rs 50,10,000 -- just above the 10% surcharge
        // threshold (Rs 50,00,000, IncomeTaxRuleVersion::$surchargeSlabs,
        // never a hardcoded flat rate in the calculator itself).
        //
        // Slab tax = 1,083,000 (worked in full in the class docblock).
        // Raw surcharge = 10% * 1,083,000 = 108,300.
        // Tax at exactly the Rs 50L threshold (no surcharge) = 1,080,000.
        // Uncapped increase = (1,083,000 + 108,300) - 1,080,000 =
        // 111,300, which exceeds the income increase of Rs 10,000 --
        // marginal relief caps the increase at Rs 10,000, so
        // tax+surcharge = 1,090,000. + 4% cess (43,600) = 1,133,600.
        $tax = (new IncomeTaxSlabCalculator)->calculateAnnualTax($this->inr('5010000.00'), $this->newRegime());

        $this->assertSame('1133600.00', $tax->amount());
    }
}
