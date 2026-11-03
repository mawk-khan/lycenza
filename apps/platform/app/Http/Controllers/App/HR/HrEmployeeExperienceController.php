<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    public function destroy(Request $request, TenantContext $context, EmployeeExperienceService $service, string $employee, string $experience): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($experience), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $experienceModel = EmployeeExperience::query()->findOrFail($experience);

        // HR.2A (S-16): an archive, with a closed reason; the record stays as evidence.
        $reason = ProfileRecordArchiveReason::from((string) $request->validate(['reason_code' => ['required', 'string', Rule::in(ProfileRecordArchiveReason::values())]], ['reason_code.*' => 'Choose why this record is being archived.'])['reason_code']);
        $service->remove($employeeModel, $experienceModel, $context->actor(), $reason);

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
