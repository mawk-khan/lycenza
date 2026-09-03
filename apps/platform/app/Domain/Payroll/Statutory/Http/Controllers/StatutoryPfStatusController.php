<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeePfStatusAdminService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "Employee PF status") -- thin HTTP
 * transport over `EmployeePfStatusAdminService`
 * (`payroll.statutory.view`/`.manage`, checked internally). Never
 * returns a raw Eloquent model -- `present()` is the single place
 * every field name crosses this boundary.
 */
class StatutoryPfStatusController extends Controller
{
    public function show(Request $request, School $school, string $employmentRecord, EmployeePfStatusAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);
        $status = $service->view($school, $employmentRecord, $request->user());

        return response()->json(['data' => $status === null ? null : $this->present($status)]);
    }

    public function store(Request $request, School $school, string $employmentRecord, EmployeePfStatusAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);

        $validated = $request->validate([
            'has_existing_pf_membership' => ['required', 'boolean'],
            'has_uan' => ['required', 'boolean'],
            'has_approved_higher_wage_contribution' => ['required', 'boolean'],
            'higher_wage_approval_reference' => ['nullable', 'string', 'max:255'],
            'higher_wage_approval_effective_from' => ['nullable', 'date'],
            'is_eps_eligible' => ['required', 'boolean'],
            'has_higher_pension_status' => ['required', 'boolean'],
        ]);

        $status = $service->configure($school, $employmentRecord, [
            'hasExistingPfMembership' => $validated['has_existing_pf_membership'],
            'hasUan' => $validated['has_uan'],
            'hasApprovedHigherWageContribution' => $validated['has_approved_higher_wage_contribution'],
            'higherWageApprovalReference' => $validated['higher_wage_approval_reference'] ?? null,
            'higherWageApprovalEffectiveFrom' => $validated['higher_wage_approval_effective_from'] ?? null,
            'isEpsEligible' => $validated['is_eps_eligible'],
            'hasHigherPensionStatus' => $validated['has_higher_pension_status'],
        ], $request->user());

        return response()->json(['data' => $this->present($status)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeePfStatus $status): array
    {
        return [
            'employmentRecordId' => $status->employment_record_id,
            'hasExistingPfMembership' => $status->has_existing_pf_membership,
            'hasUan' => $status->has_uan,
            'hasApprovedHigherWageContribution' => $status->has_approved_higher_wage_contribution,
            'higherWageApprovalReference' => $status->higher_wage_approval_reference,
            'higherWageApprovalEffectiveFrom' => $status->higher_wage_approval_effective_from?->toDateString(),
            'isEpsEligible' => $status->is_eps_eligible,
            'hasHigherPensionStatus' => $status->has_higher_pension_status,
            'updatedAt' => $status->updated_at->toIso8601String(),
        ];
    }
}
