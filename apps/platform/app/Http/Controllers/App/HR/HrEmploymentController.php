<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmployeeCategoryNotFoundException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrEmploymentController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmploymentService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', 'max:255'],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_on' => ['required', 'date'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $service->create($model, $validated, $context->actor());
        } catch (EmployeeCategoryNotFoundException) {
            throw ValidationException::withMessages(['employee_category_id' => ['That Employee Category was not found.']]);
        }

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function end(Request $request, TenantContext $context, EmploymentService $service, string $employee, string $employment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $service->end($employmentModel, $validated['ends_on'], $context->actor(), $validated['status'] ?? 'separated');

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function separate(Request $request, TenantContext $context, EmployeeLifecycleService $service, string $employee, string $employment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $service->separate($employmentModel, $validated['ends_on'], $context->actor(), $validated['status'] ?? 'separated');

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function rehire(Request $request, TenantContext $context, EmployeeLifecycleService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.assignments.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
        ]);

        $service->rehire($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }
}
