<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * EmploymentRecord. Nested under `/employees/{employee}/employment-
 * records` -- `{employment}` ownership against the route's `{employee}`
 * is verified here (EmploymentService itself has no employee-mismatch
 * guard, since a directly-injected EmploymentRecord already carries its
 * own authoritative `employee_id`; this controller re-verifies it
 * anyway so a caller cannot address one Employee's EmploymentRecord
 * through a DIFFERENT Employee's nested route, mirroring the IDOR
 * protection every other Employee-nested service already provides).
 */
class EmploymentController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmploymentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $employeeModel = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', 'max:255'],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(EmploymentRecord::STATUSES)],
        ]);

        $employment = $service->create($employeeModel, $validated, $request->user());

        return response()->json(['data' => $this->present($employment)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $employment, EmploymentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = $this->resolveOwned($employeeModel, $employment);

        $validated = $request->validate([
            'employment_type' => ['sometimes', 'string', 'max:255'],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $updated = $service->update($employmentModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function end(Request $request, School $school, string $employee, string $employment, EmploymentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = $this->resolveOwned($employeeModel, $employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $ended = $service->end($employmentModel, $validated['ends_on'], $request->user(), $validated['status'] ?? 'separated');

        return response()->json(['data' => $this->present($ended)]);
    }

    private function resolveOwned(Employee $employee, string $employmentId): EmploymentRecord
    {
        $model = EmploymentRecord::query()->findOrFail($employmentId);

        if ($model->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($model->id, $employee->id, $model->employee_id);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmploymentRecord $employment): array
    {
        return [
            'id' => $employment->id,
            'employeeId' => $employment->employee_id,
            'employeeCategoryId' => $employment->employee_category_id,
            'employmentType' => $employment->employment_type,
            'startsOn' => $employment->starts_on->toDateString(),
            'endsOn' => $employment->ends_on?->toDateString(),
            'probationEndsOn' => $employment->probation_ends_on?->toDateString(),
            'status' => $employment->status,
        ];
    }
}
