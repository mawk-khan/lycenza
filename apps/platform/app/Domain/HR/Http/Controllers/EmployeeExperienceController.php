<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * an Employee's Restricted-tier external professional experience.
 * add/update/remove only -- deliberately no verification workflow,
 * mirroring `EmployeeExperienceService`'s own intentionally minimal
 * shape.
 */
class EmployeeExperienceController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeExperienceService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $experience = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($experience)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $experience, EmployeeExperienceService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($experience), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $experienceModel = EmployeeExperience::query()->findOrFail($experience);

        $validated = $request->validate([
            'organization' => ['sometimes', 'string', 'max:255'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $updated = $service->update($employeeModel, $experienceModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $experience, EmployeeExperienceService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($experience), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $experienceModel = EmployeeExperience::query()->findOrFail($experience);

        $service->remove($employeeModel, $experienceModel, $request->user());

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeExperience $experience): array
    {
        return [
            'id' => $experience->id,
            'employeeId' => $experience->employee_id,
            'organization' => $experience->organization,
            'jobTitle' => $experience->job_title,
            'startsOn' => $experience->starts_on->toDateString(),
            'endsOn' => $experience->ends_on?->toDateString(),
            'description' => $experience->description,
            'location' => $experience->location,
            'countryCode' => $experience->country_code,
        ];
    }
}
