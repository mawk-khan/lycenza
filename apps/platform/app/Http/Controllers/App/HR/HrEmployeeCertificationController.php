<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\Exceptions\CredentialRevisionChangedException;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function destroy(Request $request, TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.2A (S-16): an archive, with a closed reason; the record stays as evidence.
        $reason = ProfileRecordArchiveReason::from((string) $request->validate(['reason_code' => ['required', 'string', Rule::in(ProfileRecordArchiveReason::values())]], ['reason_code.*' => 'Choose why this record is being archived.'])['reason_code']);
        $service->remove($employeeModel, $certificationModel, $context->actor(), $reason);

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function verify(Request $request, TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.R1-C29 (C14-N5): the revision on the page; a changed record is refused and shown, never decided.
        // A missing or malformed revision is the same stale page, shown in its own section.
        $revision = filter_var($request->input('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) {
            throw ValidationException::withMessages(['certification' => ['This page is out of date. Reload it and try again.']]);
        }
        try {
            $service->verify($employeeModel, $certificationModel, $context->actor(), $revision);
        } catch (CredentialRevisionChangedException $e) {
            throw ValidationException::withMessages(['certification' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function reject(Request $request, TenantContext $context, EmployeeCertificationService $service, string $employee, string $certification): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.qualifications.manage', $school);
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($certification), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $certificationModel = EmployeeCertification::query()->findOrFail($certification);

        // HR.R1-C29 (C14-N5): the revision on the page; a changed record is refused and shown, never decided.
        // A missing or malformed revision is the same stale page, shown in its own section.
        $revision = filter_var($request->input('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) {
            throw ValidationException::withMessages(['certification' => ['This page is out of date. Reload it and try again.']]);
        }
        try {
            $service->reject($employeeModel, $certificationModel, $context->actor(), $revision);
        } catch (CredentialRevisionChangedException $e) {
            throw ValidationException::withMessages(['certification' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
