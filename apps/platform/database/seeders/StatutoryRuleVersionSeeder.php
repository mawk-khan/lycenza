<?php

namespace Database\Seeders;

use App\Domain\Payroll\Statutory\Infrastructure\PayrollEsiRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollIncomeTaxRuleSlab;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollIncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollLwfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollPfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollProfessionalTaxRuleVersion;
use Illuminate\Database\Seeder;

/**
 * Checkpoint 9.6C (ADR 0035) -- the effective-1-April-2026 statutory
 * rule versions, seeded as platform reference data. Every numeric
 * value here matches the corresponding
 * `App\Domain\Payroll\Statutory\Calculation\*RuleVersion::effectiveApril2026()`
 * pure value object exactly -- the Checkpoint 9.6B golden fixtures
 * exercise those pure classes directly, so this seeder is what makes
 * the SAME numbers available to the DB-backed Application-layer
 * services Checkpoint 9.6D adds on top. `updateOrCreate` keyed on each
 * table's own unique constraint makes re-running this seeder safe
 * (CapabilityAndRoleSeeder's own established idempotence pattern).
 */
class StatutoryRuleVersionSeeder extends Seeder
{
    public function run(): void
    {
        $pf = PayrollPfRuleVersion::query()->updateOrCreate(
            ['effective_from' => '2026-04-01'],
            [
                'status' => 'active',
                'legal_reference' => 'SCH/PAY/REG/2026-9.6',
                'employee_contribution_rate' => '0.1200',
                'employer_contribution_rate' => '0.1200',
                'eps_rate' => '0.0833',
                'edli_rate' => '0.0050',
                'admin_charge_rate' => '0.0050',
                'membership_wage_ceiling' => '15000.00',
                'eps_wage_ceiling' => '15000.00',
                'edli_wage_ceiling' => '15000.00',
                'admin_charge_minimum' => '500.00',
            ],
        );

        $esi = PayrollEsiRuleVersion::query()->updateOrCreate(
            ['effective_from' => '2026-04-01'],
            [
                'status' => 'active',
                'legal_reference' => 'SCH/PAY/REG/2026-9.6',
                'employee_contribution_rate' => '0.0075',
                'employer_contribution_rate' => '0.0325',
                'wage_threshold' => '21000.00',
                'average_daily_wage_exemption_threshold' => '176.00',
            ],
        );

        $lwf = PayrollLwfRuleVersion::query()->updateOrCreate(
            ['jurisdiction' => 'Telangana', 'effective_from' => '2026-04-01'],
            [
                'status' => 'active',
                'legal_reference' => 'SCH/PAY/REG/2026-9.6',
                'employee_amount' => '2.00',
                'employer_amount' => '5.00',
            ],
        );

        $pt = PayrollProfessionalTaxRuleVersion::query()->updateOrCreate(
            ['jurisdiction' => 'Telangana', 'effective_from' => '2026-04-01'],
            ['status' => 'active', 'legal_reference' => 'SCH/PAY/REG/2026-9.6'],
        );
        foreach ([
            ['upper_bound' => '15000.00', 'amount' => '0.00', 'sort_order' => 1],
            ['upper_bound' => '20000.00', 'amount' => '150.00', 'sort_order' => 2],
            ['upper_bound' => null, 'amount' => '200.00', 'sort_order' => 3],
        ] as $slab) {
            $pt->slabs()->updateOrCreate(['sort_order' => $slab['sort_order']], $slab);
        }

        $newRegime = PayrollIncomeTaxRuleVersion::query()->updateOrCreate(
            ['regime' => 'new', 'effective_from' => '2026-04-01'],
            [
                'status' => 'active',
                'legal_reference' => 'SCH/PAY/REG/2026-9.6 (Income-tax Act, 2025, Section 392)',
                'standard_deduction' => '75000.00',
                'rebate_qualifying_income_threshold' => '1200000.00',
                'rebate_maximum' => '60000.00',
                'cess_rate' => '0.04',
            ],
        );
        foreach ([
            ['slab_kind' => 'income', 'upper_bound' => '400000.00', 'rate' => '0.00', 'sort_order' => 1],
            ['slab_kind' => 'income', 'upper_bound' => '800000.00', 'rate' => '0.05', 'sort_order' => 2],
            ['slab_kind' => 'income', 'upper_bound' => '1200000.00', 'rate' => '0.10', 'sort_order' => 3],
            ['slab_kind' => 'income', 'upper_bound' => '1600000.00', 'rate' => '0.15', 'sort_order' => 4],
            ['slab_kind' => 'income', 'upper_bound' => '2000000.00', 'rate' => '0.20', 'sort_order' => 5],
            ['slab_kind' => 'income', 'upper_bound' => '2400000.00', 'rate' => '0.25', 'sort_order' => 6],
            ['slab_kind' => 'income', 'upper_bound' => null, 'rate' => '0.30', 'sort_order' => 7],
            ['slab_kind' => 'surcharge', 'upper_bound' => '5000000.00', 'rate' => '0.10', 'sort_order' => 1],
            ['slab_kind' => 'surcharge', 'upper_bound' => '10000000.00', 'rate' => '0.15', 'sort_order' => 2],
            ['slab_kind' => 'surcharge', 'upper_bound' => '20000000.00', 'rate' => '0.25', 'sort_order' => 3],
        ] as $slab) {
            PayrollIncomeTaxRuleSlab::query()->updateOrCreate(
                ['rule_version_id' => $newRegime->id, 'slab_kind' => $slab['slab_kind'], 'sort_order' => $slab['sort_order']],
                $slab + ['rule_version_id' => $newRegime->id],
            );
        }

        $oldRegime = PayrollIncomeTaxRuleVersion::query()->updateOrCreate(
            ['regime' => 'old', 'effective_from' => '2026-04-01'],
            [
                'status' => 'active',
                'legal_reference' => 'SCH/PAY/REG/2026-9.6 (Income-tax Act, 2025, Section 392 -- old regime)',
                'standard_deduction' => '50000.00',
                'rebate_qualifying_income_threshold' => '500000.00',
                'rebate_maximum' => '12500.00',
                'cess_rate' => '0.04',
            ],
        );
        foreach ([
            ['slab_kind' => 'income', 'upper_bound' => '250000.00', 'rate' => '0.00', 'sort_order' => 1],
            ['slab_kind' => 'income', 'upper_bound' => '500000.00', 'rate' => '0.05', 'sort_order' => 2],
            ['slab_kind' => 'income', 'upper_bound' => '1000000.00', 'rate' => '0.20', 'sort_order' => 3],
            ['slab_kind' => 'income', 'upper_bound' => null, 'rate' => '0.30', 'sort_order' => 4],
            ['slab_kind' => 'surcharge', 'upper_bound' => '5000000.00', 'rate' => '0.10', 'sort_order' => 1],
            ['slab_kind' => 'surcharge', 'upper_bound' => '10000000.00', 'rate' => '0.15', 'sort_order' => 2],
            ['slab_kind' => 'surcharge', 'upper_bound' => '20000000.00', 'rate' => '0.25', 'sort_order' => 3],
            ['slab_kind' => 'surcharge', 'upper_bound' => '50000000.00', 'rate' => '0.37', 'sort_order' => 4],
        ] as $slab) {
            $oldRegime->incomeSlabs()->getRelated()->query()->updateOrCreate(
                ['rule_version_id' => $oldRegime->id, 'slab_kind' => $slab['slab_kind'], 'sort_order' => $slab['sort_order']],
                $slab + ['rule_version_id' => $oldRegime->id],
            );
        }
    }
}
