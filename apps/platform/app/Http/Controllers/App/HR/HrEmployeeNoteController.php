<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HrEmployeeNoteController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmployeeNoteService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.notes.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'classification_tier' => ['sometimes', Rule::in(EmployeeNote::CLASSIFICATION_TIERS)],
        ]);

        $service->add($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function destroy(TenantContext $context, EmployeeNoteService $service, string $employee, string $note): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.notes.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($note), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $noteModel = EmployeeNote::query()->findOrFail($note);

        $service->remove($employeeModel, $noteModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
