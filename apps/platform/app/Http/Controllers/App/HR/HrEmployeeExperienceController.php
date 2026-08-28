<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HrEmployeeExperienceController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeExperienceService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeExperienceService $service, string $employee, string $experience): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($experience), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $experienceModel = EmployeeExperience::query()->findOrFail($experience);

        $service->remove($employeeModel, $experienceModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
