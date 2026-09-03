<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeTaxProfileAdminService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "Employee tax profile") -- thin HTTP
 * transport over `EmployeeTaxProfileAdminService`. Exposes only the
 * fixed, typed field set the calculation engine already understands
 * -- no arbitrary tax-code scripting.
 */
class StatutoryTaxProfileController extends Controller
{
    public function index(Request $request, School $school, string $employmentRecord, EmployeeTaxProfileAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);
        $profiles = $service->history($school, $employmentRecord, $request->user());

        return response()->json(['data' => $profiles->map(fn (EmployeeTaxProfile $p) => $this->present($p))->all()]);
    }

    public function store(Request $request, School $school, string $employmentRecord, EmployeeTaxProfileAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);

        $validated = $request->validate([
            'fiscal_year_start' => ['required', 'date'],
            'regime' => ['required', 'in:old,new'],
            'regime_switch_policy_reference' => ['nullable', 'string', 'max:255'],
            'previous_employer_income' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'previous_employer_tds' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'declared_other_income' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'declared_deductions' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $profile = $service->configure($school, $employmentRecord, $validated['fiscal_year_start'], [
            'regime' => $validated['regime'],
            'regimeSwitchPolicyReference' => $validated['regime_switch_policy_reference'] ?? null,
            'previousEmployerIncome' => $validated['previous_employer_income'],
            'previousEmployerTds' => $validated['previous_employer_tds'],
            'declaredOtherIncome' => $validated['declared_other_income'],
            'declaredDeductions' => $validated['declared_deductions'],
        ], $request->user());

        return response()->json(['data' => $this->present($profile)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeTaxProfile $profile): array
    {
        return [
            'employmentRecordId' => $profile->employment_record_id,
            'fiscalYearStart' => $profile->fiscal_year_start->toDateString(),
            'regime' => $profile->regime,
            'regimeSwitchPolicyReference' => $profile->regime_switch_policy_reference,
            'previousEmployerIncome' => $profile->previous_employer_income,
            'previousEmployerTds' => $profile->previous_employer_tds,
            'declaredOtherIncome' => $profile->declared_other_income,
            'declaredDeductions' => $profile->declared_deductions,
        ];
    }
}
