<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HrEmployeeEmergencyContactController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeEmergencyContactService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeEmergencyContactService $service, string $employee, string $contact): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($contact), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $contactModel = EmployeeEmergencyContact::query()->findOrFail($contact);

        $service->remove($employeeModel, $contactModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function setPrimary(TenantContext $context, EmployeeEmergencyContactService $service, string $employee, string $contact): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($contact), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $contactModel = EmployeeEmergencyContact::query()->findOrFail($contact);

        $service->setPrimary($employeeModel, $contactModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
