<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HrEmployeeQualificationController extends Controller
{
    use AuthorizesCapability;

    private const array QUALIFICATION_TYPES = [
        'secondary', 'higher_secondary', 'diploma', 'bachelors', 'masters', 'doctorate', 'professional', 'other',
    ];

    public function store(Request $request, TenantContext $context, EmployeeQualificationService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'qualification_type' => ['required', Rule::in(self::QUALIFICATION_TYPES)],
            'qualification_name' => ['required', 'string', 'max:255'],
            'institution' => ['required', 'string', 'max:255'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'completed_on' => ['sometimes', 'nullable', 'date'],
            'grade_or_result' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $service->remove($employeeModel, $qualificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function verify(TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $service->verify($employeeModel, $qualificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function reject(TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        $service->reject($employeeModel, $qualificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
