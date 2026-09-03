<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\Payroll\Statutory\Application\Admin\StatutoryRuleStatusReadService;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollEsiRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollIncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollLwfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollPfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollProfessionalTaxRuleVersion;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "Statutory rule status") -- READ-ONLY.
 * No store/update action exists here on purpose -- rule-version
 * content is code/seed-controlled reference data, never a runtime
 * tenant-editable "rules engine" (see `StatutoryRuleStatusReadService`'s
 * own docblock).
 */
class StatutoryRuleStatusController extends Controller
{
    public function show(Request $request, School $school, StatutoryRuleStatusReadService $service): JsonResponse
    {
        $versions = $service->activeRuleVersions($school, $request->user());

        return response()->json(['data' => [
            'pf' => $this->presentPf($versions['pf']),
            'esi' => $this->presentEsi($versions['esi']),
            'professionalTax' => $this->presentProfessionalTax($versions['professionalTax']),
            'lwf' => $this->presentLwf($versions['lwf']),
            'incomeTaxNewRegime' => $this->presentIncomeTax($versions['incomeTaxNewRegime']),
            'incomeTaxOldRegime' => $this->presentIncomeTax($versions['incomeTaxOldRegime']),
        ]]);
    }

    private function presentPf(?PayrollPfRuleVersion $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return [
            'effectiveFrom' => $v->effective_from->toDateString(),
            'legalReference' => $v->legal_reference,
            'employeeContributionRate' => $v->employee_contribution_rate,
            'employerContributionRate' => $v->employer_contribution_rate,
            'epsRate' => $v->eps_rate,
            'edliRate' => $v->edli_rate,
            'adminChargeRate' => $v->admin_charge_rate,
            'membershipWageCeiling' => $v->membership_wage_ceiling,
            'epsWageCeiling' => $v->eps_wage_ceiling,
            'edliWageCeiling' => $v->edli_wage_ceiling,
            'adminChargeMinimum' => $v->admin_charge_minimum,
        ];
    }

    private function presentEsi(?PayrollEsiRuleVersion $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return [
            'effectiveFrom' => $v->effective_from->toDateString(),
            'legalReference' => $v->legal_reference,
            'employeeContributionRate' => $v->employee_contribution_rate,
            'employerContributionRate' => $v->employer_contribution_rate,
            'wageThreshold' => $v->wage_threshold,
            'averageDailyWageExemptionThreshold' => $v->average_daily_wage_exemption_threshold,
            'disabilityThreshold' => null,
            'disabilityThresholdStatus' => 'DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED',
        ];
    }

    private function presentProfessionalTax(?PayrollProfessionalTaxRuleVersion $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return [
            'jurisdiction' => $v->jurisdiction,
            'effectiveFrom' => $v->effective_from->toDateString(),
            'legalReference' => $v->legal_reference,
            'slabs' => $v->slabs->map(fn ($s) => ['upTo' => $s->upper_bound, 'amount' => $s->amount])->all(),
        ];
    }

    private function presentLwf(?PayrollLwfRuleVersion $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return [
            'jurisdiction' => $v->jurisdiction,
            'effectiveFrom' => $v->effective_from->toDateString(),
            'legalReference' => $v->legal_reference,
            'employeeAmount' => $v->employee_amount,
            'employerAmount' => $v->employer_amount,
        ];
    }

    private function presentIncomeTax(?PayrollIncomeTaxRuleVersion $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return [
            'regime' => $v->regime,
            'effectiveFrom' => $v->effective_from->toDateString(),
            'legalReference' => $v->legal_reference,
            'standardDeduction' => $v->standard_deduction,
            'rebateQualifyingIncomeThreshold' => $v->rebate_qualifying_income_threshold,
            'rebateMaximum' => $v->rebate_maximum,
            'cessRate' => $v->cess_rate,
            'incomeSlabs' => $v->incomeSlabs->map(fn ($s) => ['upTo' => $s->upper_bound, 'rate' => $s->rate])->all(),
            'surchargeSlabs' => $v->surchargeSlabs->map(fn ($s) => ['threshold' => $s->upper_bound, 'rate' => $s->rate])->all(),
        ];
    }
}
