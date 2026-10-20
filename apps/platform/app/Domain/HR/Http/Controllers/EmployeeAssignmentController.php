<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * EmployeeAssignment, nested under
 * `/employees/{employee}/employment-records/{employment}/assignments`.
 * `{assignment}` ownership against the route's `{employee}`/
 * `{employment}` is re-verified here for the same IDOR-safety reason as
 * `EmploymentController::resolveOwned()` -- `EmployeeAssignmentService`
 * itself has no `employee_id` column to check against (see its own
 * docblock), so the nested-route ownership check belongs at this
 * transport layer. `setManager()` (reporting-hierarchy mutation) lives
 * here too, on the same `{assignment}` resource, rather than a separate
 * controller -- it is still fundamentally "mutate this Assignment's one
 * manager pointer," matching `ReportingHierarchyService`'s own single-
 * responsibility scope.
 */
class EmployeeAssignmentController extends Controller
{
    // ORG.7E-1 (ADR 0076 §43): Campus-adopted (`hr.employees.assignments.manage`).
    // Every record resolves through HrEmployeeAccess -- a Campus-scoped caller
    // reaches only the current employment and CURRENT in-scope assignments of
    // an Employee in scope (404 otherwise) -- and the services decide the write
    // rule under lock.
    use AuthorizesCampusScope;

    public function store(
        Request $request,
        School $school,
        string $employee,
        string $employment,
        EmployeeAssignmentService $service,
        HrEmployeeAccess $access,
    ): JsonResponse {
        $scope = $this->authorizeCampusScope('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = $access->employee($school, $scope, $employee);
        $employmentModel = $this->resolveOwnedEmployment($access, $school, $scope, $employeeModel, $employment);

        $validated = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'position_id' => ['required', 'uuid'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $position = Position::query()->findOrFail($validated['position_id']);
        $campus = isset($validated['campus_id'])
            ? Campus::query()->findOrFail($validated['campus_id'])
            : null;
        $department = isset($validated['department_id'])
            ? Department::query()->findOrFail($validated['department_id'])
            : null;

        $assignment = $service->create(
            $employmentModel,
            ['starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on'] ?? null],
            $position,
            $request->user(),
            campus: $campus,
            department: $department,
        );

        return response()->json(['data' => $this->present($assignment)], 201);
    }

    public function end(
        Request $request,
        School $school,
        string $employee,
        string $employment,
        string $assignment,
        EmployeeAssignmentService $service,
        HrEmployeeAccess $access,
    ): JsonResponse {
        $scope = $this->authorizeCampusScope('hr.employees.assignments.manage', $school);
        [$employeeModel, , $assignmentModel] = $this->resolveChain($access, $school, $scope, $employee, $employment, $assignment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $ended = $service->end($assignmentModel, $validated['ends_on'], $request->user());

        return response()->json(['data' => $this->present($ended)]);
    }

    public function setPrimary(
        Request $request,
        School $school,
        string $employee,
        string $employment,
        string $assignment,
        EmployeeAssignmentService $service,
        HrEmployeeAccess $access,
    ): JsonResponse {
        $scope = $this->authorizeCampusScope('hr.employees.assignments.manage', $school);
        [, , $assignmentModel] = $this->resolveChain($access, $school, $scope, $employee, $employment, $assignment);

        $updated = $service->setPrimary($assignmentModel, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function setManager(
        Request $request,
        School $school,
        string $employee,
        string $employment,
        string $assignment,
        ReportingHierarchyService $service,
        HrEmployeeAccess $access,
    ): JsonResponse {
        $scope = $this->authorizeCampusScope('hr.employees.assignments.manage', $school);
        [, , $subordinate] = $this->resolveChain($access, $school, $scope, $employee, $employment, $assignment);

        $validated = $request->validate(['manager_assignment_id' => ['sometimes', 'nullable', 'uuid']]);

        // A Campus-scoped caller names only a current in-scope manager assignment (404 otherwise).
        $manager = isset($validated['manager_assignment_id'])
            ? $access->assignment($school, $scope, $validated['manager_assignment_id'])
            : null;

        $updated = $service->setManager($subordinate, $manager, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array{0: Employee, 1: EmploymentRecord, 2: EmployeeAssignment}
     */
    private function resolveChain(HrEmployeeAccess $access, School $school, CampusScope $scope, string $employee, string $employment, string $assignment): array
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment) || ! Str::isUuid($assignment), 404);

        $employeeModel = $access->employee($school, $scope, $employee);
        $employmentModel = $this->resolveOwnedEmployment($access, $school, $scope, $employeeModel, $employment);
        $assignmentModel = $access->assignment($school, $scope, $assignment);

        if ($assignmentModel->employment_record_id !== $employmentModel->id) {
            throw new EmployeeOwnershipMismatchException($assignmentModel->id, $employmentModel->id, $assignmentModel->employment_record_id);
        }

        return [$employeeModel, $employmentModel, $assignmentModel];
    }

    private function resolveOwnedEmployment(HrEmployeeAccess $access, School $school, CampusScope $scope, Employee $employee, string $employmentId): EmploymentRecord
    {
        $model = $access->employment($school, $scope, $employmentId);

        if ($model->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($model->id, $employee->id, $model->employee_id);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'employmentRecordId' => $assignment->employment_record_id,
            'positionId' => $assignment->position_id,
            'departmentId' => $assignment->department_id,
            'campusId' => $assignment->campus_id,
            'managerAssignmentId' => $assignment->manager_assignment_id,
            'isPrimary' => $assignment->is_primary,
            'startsOn' => $assignment->starts_on->toDateString(),
            'endsOn' => $assignment->ends_on?->toDateString(),
        ];
    }
}
