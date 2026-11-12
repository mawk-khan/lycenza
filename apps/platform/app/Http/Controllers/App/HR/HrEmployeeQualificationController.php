<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\Exceptions\CredentialRevisionChangedException;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function destroy(Request $request, TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        // HR.2A (S-16): an archive, with a closed reason; the record stays as evidence.
        $reason = ProfileRecordArchiveReason::from((string) $request->validate(['reason_code' => ['required', 'string', Rule::in(ProfileRecordArchiveReason::values())]], ['reason_code.*' => 'Choose why this record is being archived.'])['reason_code']);
        $service->remove($employeeModel, $qualificationModel, $context->actor(), $reason);

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function verify(Request $request, TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        // HR.R1-C29 (C14-N5): the revision on the page; a changed record is refused and shown, never decided.
        // A missing or malformed revision is the same stale page, shown in its own section.
        $revision = filter_var($request->input('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) {
            throw ValidationException::withMessages(['qualification' => ['This page is out of date. Reload it and try again.']]);
        }
        try {
            $service->verify($employeeModel, $qualificationModel, $context->actor(), $revision);
        } catch (CredentialRevisionChangedException $e) {
            throw ValidationException::withMessages(['qualification' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function reject(Request $request, TenantContext $context, EmployeeQualificationService $service, string $employee, string $qualification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($qualification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $qualificationModel = EmployeeQualification::query()->findOrFail($qualification);

        // HR.R1-C29 (C14-N5): the revision on the page; a changed record is refused and shown, never decided.
        // A missing or malformed revision is the same stale page, shown in its own section.
        $revision = filter_var($request->input('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) {
            throw ValidationException::withMessages(['qualification' => ['This page is out of date. Reload it and try again.']]);
        }
        try {
            $service->reject($employeeModel, $qualificationModel, $context->actor(), $revision);
        } catch (CredentialRevisionChangedException $e) {
            throw ValidationException::withMessages(['qualification' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
