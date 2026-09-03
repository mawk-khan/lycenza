<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Application\ManualOverrideLineInput;
use App\Domain\Payroll\Application\PayrollCalculationEngine;
use App\Domain\Payroll\Application\StructureComponentInput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9.3 -- golden-fixture tests for the pure, DB-free
 * `PayrollCalculationEngine` (ADR 0034 "Calculation semantics"). No
 * database involved -- every input is a plain DTO.
 */
class PayrollCalculationEngineTest extends TestCase
{
    private function engine(): PayrollCalculationEngine
    {
        return new PayrollCalculationEngine;
    }

    #[Test]
    public function a_fixed_only_structure_computes_gross_equal_to_the_fixed_value(): void
    {
        $components = [
            new StructureComponentInput('sc1', 'basic', 'fixed_amount', null, null, true, null),
        ];

        $result = $this->engine()->calculateFromStructure($components, ['sc1' => '50000.00']);

        $this->assertSame('50000.00', $result->grossAmount);
        $this->assertSame('0.00', $result->totalDeductions);
        $this->assertSame('50000.00', $result->netAmount);
    }

    #[Test]
    public function a_percentage_derived_component_is_computed_from_its_base(): void
    {
        $components = [
            new StructureComponentInput('sc1', 'basic', 'fixed_amount', null, null, true, null),
            new StructureComponentInput('sc2', 'hra', 'percentage_of_base', 'sc1', '0.400000', true, null),
        ];

        $result = $this->engine()->calculateFromStructure($components, ['sc1' => '50000.00']);

        $this->assertSame('70000.00', $result->grossAmount); // 50000 + (50000 * 0.4)
        $lines = collect($result->lines)->keyBy('salaryComponentId');
        $this->assertSame('20000.00', $lines['hra']->amount);
    }

    #[Test]
    public function rounding_is_half_up_at_the_line_level(): void
    {
        $components = [
            new StructureComponentInput('sc1', 'basic', 'fixed_amount', null, null, true, null),
            new StructureComponentInput('sc2', 'allowance', 'percentage_of_base', 'sc1', '0.333350', true, null),
        ];

        $result = $this->engine()->calculateFromStructure($components, ['sc1' => '100.00']);

        $lines = collect($result->lines)->keyBy('salaryComponentId');
        $this->assertSame('33.34', $lines['allowance']->amount);
    }

    #[Test]
    public function a_zero_amount_component_is_a_valid_line(): void
    {
        $components = [
            new StructureComponentInput('sc1', 'basic', 'fixed_amount', null, null, true, null),
            new StructureComponentInput('sc2', 'bonus', 'fixed_amount', null, null, true, null),
        ];

        $result = $this->engine()->calculateFromStructure($components, ['sc1' => '50000.00', 'sc2' => '0.00']);

        $this->assertCount(2, $result->lines);
        $this->assertSame('50000.00', $result->grossAmount);
    }

    #[Test]
    public function deductions_reduce_net_but_not_gross(): void
    {
        $components = [
            new StructureComponentInput('sc1', 'basic', 'fixed_amount', null, null, true, null),
            new StructureComponentInput('sc2', 'pf', 'percentage_of_base', 'sc1', '0.120000', false, 'ledger-1'),
        ];

        $result = $this->engine()->calculateFromStructure($components, ['sc1' => '50000.00']);

        $this->assertSame('50000.00', $result->grossAmount);
        $this->assertSame('6000.00', $result->totalDeductions);
        $this->assertSame('44000.00', $result->netAmount);

        $pfLine = collect($result->lines)->firstWhere('salaryComponentId', 'pf');
        $this->assertSame('ledger-1', $pfLine->resolvedLedgerAccountId);
    }

    #[Test]
    public function a_manual_override_computes_gross_deductions_and_net_from_absolute_lines(): void
    {
        $overrides = [
            new ManualOverrideLineInput('basic', '25000.00', true, null),
            new ManualOverrideLineInput('pf', '3000.00', false, 'ledger-1'),
        ];

        $result = $this->engine()->calculateFromManualOverrides($overrides);

        $this->assertSame('25000.00', $result->grossAmount);
        $this->assertSame('3000.00', $result->totalDeductions);
        $this->assertSame('22000.00', $result->netAmount);
    }

    #[Test]
    public function a_manual_override_can_produce_a_negative_net_which_the_engine_does_not_itself_reject(): void
    {
        $overrides = [
            new ManualOverrideLineInput('basic', '1000.00', true, null),
            new ManualOverrideLineInput('pf', '5000.00', false, 'ledger-1'),
        ];

        $result = $this->engine()->calculateFromManualOverrides($overrides);

        $this->assertSame('-4000.00', $result->netAmount);
    }
}
