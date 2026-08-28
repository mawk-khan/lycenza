<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * an Employee's Restricted-tier qualification history. `verify()`/
 * `reject()` are dedicated actions, never a generic `status` field on
 * `update()` -- `verification_status`/`verified_at` are stripped by the
 * service from any caller-supplied `update()` attributes.
 */
class EmployeeQualificationController extends Controller
{
    private const array QUALIFICATION_TYPES = [
        'secondary', 'higher_secondary', 'diploma', 'bachelors', 'masters', 'doctorate', 'professional', 'other',
    ];

    public function store(Request $request, School $school, string $employee, EmployeeQualificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'qualification_type' => ['required', Rule::in(self::QUALIFICATION_TYPES)],
            'qualification_name' => ['required', 'string', 'max:255'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'institution' => ['required', 'string', 'max:255'],
            'awarding_body' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'completed_on' => ['sometimes', 'nullable', 'date'],
            'grade_or_result' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $qualification = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($qualification)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $qualification, EmployeeQualificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $validated = $request->validate([
            'qualification_type' => ['sometimes', Rule::in(self::QUALIFICATION_TYPES)],
            'qualification_name' => ['sometimes', 'string', 'max:255'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'institution' => ['sometimes', 'string', 'max:255'],
            'awarding_body' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'completed_on' => ['sometimes', 'nullable', 'date'],
            'grade_or_result' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $updated = $service->update($employeeModel, $qualificationModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $qualification, EmployeeQualificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $service->remove($employeeModel, $qualificationModel, $request->user());

        return response()->json(status: 204);
    }

    public function verify(Request $request, School $school, string $employee, string $qualification, EmployeeQualificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $updated = $service->verify($employeeModel, $qualificationModel, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function reject(Request $request, School $school, string $employee, string $qualification, EmployeeQualificationService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $updated = $service->reject($employeeModel, $qualificationModel, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeQualification $qualification): array
    {
        return [
            'id' => $qualification->id,
            'employeeId' => $qualification->employee_id,
            'qualificationType' => $qualification->qualification_type,
            'qualificationName' => $qualification->qualification_name,
            'specialization' => $qualification->specialization,
            'institution' => $qualification->institution,
            'awardingBody' => $qualification->awarding_body,
            'countryCode' => $qualification->country_code,
            'startsOn' => $qualification->starts_on?->toDateString(),
            'completedOn' => $qualification->completed_on?->toDateString(),
            'gradeOrResult' => $qualification->grade_or_result,
            'verificationStatus' => $qualification->verification_status,
            'verifiedAt' => $qualification->verified_at?->toIso8601String(),
        ];
    }
}
