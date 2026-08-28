<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8A closure correction -- `setManager()` deliberately takes a
 * raw manager Assignment id typed/pasted by the HR admin, not a
 * searchable picker across every Employee's Assignments -- building a
 * cross-Employee Assignment search UI is out of this correction's
 * scope (item 2 asks for the promised administrative UI, not a new
 * search feature `docs/modules/HR.md` never specified). Any invalid or
 * cross-School id is rejected cleanly by `ReportingHierarchyService`
 * itself.
 */
class HrEmployeeAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeAssignmentService $service, string $employee, string $employment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        $validated = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'position_id' => ['required', 'uuid'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        try {
            $position = Position::query()->findOrFail($validated['position_id']);
            $campus = isset($validated['campus_id']) ? Campus::query()->findOrFail($validated['campus_id']) : null;
            $department = isset($validated['department_id']) ? Department::query()->findOrFail($validated['department_id']) : null;

            $service->create(
                $employmentModel,
                ['starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on'] ?? null],
                $position,
                $context->actor(),
                campus: $campus,
                department: $department,
            );
        } catch (HrException $e) {
            throw ValidationException::withMessages(['position_id' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function end(Request $request, TenantContext $context, EmployeeAssignmentService $service, string $employee, string $employment, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        [$employeeModel, , $assignmentModel] = $this->resolveChain($employee, $employment, $assignment);

        $validated = $request->validate(['ends_on' => ['required', 'date']]);

        $service->end($assignmentModel, $validated['ends_on'], $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function setPrimary(TenantContext $context, EmployeeAssignmentService $service, string $employee, string $employment, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        [$employeeModel, , $assignmentModel] = $this->resolveChain($employee, $employment, $assignment);

        try {
            $service->setPrimary($assignmentModel, $context->actor());
        } catch (HrException $e) {
            throw ValidationException::withMessages(['assignment' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function setManager(Request $request, TenantContext $context, ReportingHierarchyService $service, string $employee, string $employment, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        [$employeeModel, , $subordinate] = $this->resolveChain($employee, $employment, $assignment);

        $validated = $request->validate(['manager_assignment_id' => ['sometimes', 'nullable', 'uuid']]);

        $manager = isset($validated['manager_assignment_id'])
            ? EmployeeAssignment::query()->find($validated['manager_assignment_id'])
            : null;

        if (isset($validated['manager_assignment_id']) && $manager === null) {
            throw ValidationException::withMessages(['manager_assignment_id' => ['No Assignment with that id was found in this School.']]);
        }

        try {
            $service->setManager($subordinate, $manager, $context->actor());
        } catch (HrException $e) {
            throw ValidationException::withMessages(['manager_assignment_id' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    /**
     * @return array{0: Employee, 1: EmploymentRecord, 2: EmployeeAssignment}
     */
    private function resolveChain(string $employee, string $employment, string $assignment): array
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment) || ! Str::isUuid($assignment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);
        abort_if($employmentModel->employee_id !== $employeeModel->id, 404);

        $assignmentModel = EmployeeAssignment::query()->findOrFail($assignment);
        abort_if($assignmentModel->employment_record_id !== $employmentModel->id, 404);

        return [$employeeModel, $employmentModel, $assignmentModel];
    }
}
