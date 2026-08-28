<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HrEmployeeCertificationController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeCertificationService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'issuer' => ['required', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        $service->remove($employeeModel, $certificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function verify(TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        $service->verify($employeeModel, $certificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function reject(TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        $service->reject($employeeModel, $certificationModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
