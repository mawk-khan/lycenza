<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HrEmployeePersonalDetailController extends Controller
{
    use AuthorizesCapability;

    public function update(Request $request, TenantContext $context, EmployeePersonalDetailService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.personal.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:255'],
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'personal_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $service->setDetails($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }
}
