<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * the 8A.13 Employee lifecycle COMMAND layer (`separate()`/`rehire()`).
 * Thin adapter over `EmployeeLifecycleService`, which is itself already
 * an orchestrator over `EmploymentService`/`EmployeeAssignmentService`
 * -- this controller adds no lifecycle logic of its own.
 */
class EmployeeLifecycleController extends Controller
{
    public function separate(
        Request $request,
        School $school,
        string $employee,
        string $employment,
        EmployeeLifecycleService $service,
    ): JsonResponse {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        if ($employmentModel->employee_id !== $employeeModel->id) {
            throw new EmployeeOwnershipMismatchException($employmentModel->id, $employeeModel->id, $employmentModel->employee_id);
        }

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $separated = $service->separate($employmentModel, $validated['ends_on'], $request->user(), $validated['status'] ?? 'separated');

        return response()->json(['data' => [
            'id' => $separated->id,
            'employeeId' => $separated->employee_id,
            'endsOn' => $separated->ends_on?->toDateString(),
            'status' => $separated->status,
        ]]);
    }

    public function rehire(Request $request, School $school, string $employee, EmployeeLifecycleService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $employeeModel = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
            'assignment' => ['sometimes', 'nullable', 'array'],
            'assignment.starts_on' => ['required_with:assignment', 'date'],
            'assignment.ends_on' => ['sometimes', 'nullable', 'date'],
            'assignment.position_id' => ['required_with:assignment', 'uuid'],
            'assignment.campus_id' => ['sometimes', 'nullable', 'uuid'],
            'assignment.department_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $employmentAttributes = [
            'employment_type' => $validated['employment_type'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
            'probation_ends_on' => $validated['probation_ends_on'] ?? null,
        ];

        $assignmentAttributes = null;
        $position = null;
        $department = null;
        $campus = null;

        if (! empty($validated['assignment'])) {
            $assignment = $validated['assignment'];
            $assignmentAttributes = ['starts_on' => $assignment['starts_on'], 'ends_on' => $assignment['ends_on'] ?? null];
            $position = Position::query()->findOrFail($assignment['position_id']);
            $department = isset($assignment['department_id'])
                ? Department::query()->findOrFail($assignment['department_id'])
                : null;
            $campus = isset($assignment['campus_id'])
                ? Campus::query()->findOrFail($assignment['campus_id'])
                : null;
        }

        $employment = $service->rehire(
            $employeeModel,
            $employmentAttributes,
            $request->user(),
            assignmentAttributes: $assignmentAttributes,
            position: $position,
            department: $department,
            campus: $campus,
        );

        return response()->json(['data' => [
            'id' => $employment->id,
            'employeeId' => $employment->employee_id,
            'employmentType' => $employment->employment_type,
            'startsOn' => $employment->starts_on->toDateString(),
            'status' => $employment->status,
        ]], 201);
    }
}
