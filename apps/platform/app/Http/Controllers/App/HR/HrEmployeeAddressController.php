<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HrEmployeeAddressController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeAddressService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'address_type' => ['required', Rule::in(['current', 'permanent', 'mailing', 'other'])],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'string', 'size:2'],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeAddressService $service, string $employee, string $address): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($address), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $addressModel = EmployeeAddress::query()->findOrFail($address);

        $service->remove($employeeModel, $addressModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
