<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\Profile\EmployeeReferenceCodeService;
use App\Domain\HR\Application\Profile\EmployeeReferenceKind;
use App\Domain\HR\Application\Profile\ReferenceCodeRetireReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeReferenceCode;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HR.2A (D1-03): an Employee's external reference codes (Staff Reference,
 * ERP, legacy) on the Profile. Declared whole-School operations of the
 * Campus-adopted `hr.employees.manage` (the route floor, which a Campus grant
 * never satisfies; the service re-checks). Conflicts are form errors.
 */
class HrEmployeeReferenceCodeController extends Controller
{
    public function store(Request $request, TenantContext $context, EmployeeReferenceCodeService $service, string $employee): RedirectResponse
    {
        $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);
        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(EmployeeReferenceKind::values())],
            'value' => ['required', 'string', 'max:64'],
        ], [
            'kind.*' => 'Choose the kind of reference code.',
            'value.*' => 'Enter the reference code.',
        ]);

        $service->record($model, EmployeeReferenceKind::from($validated['kind']), (string) $validated['value'], $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function retire(Request $request, TenantContext $context, EmployeeReferenceCodeService $service, string $employee, string $referenceCode): RedirectResponse
    {
        $context->requireSchool();
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($referenceCode), 404);
        $model = Employee::query()->findOrFail($employee);
        $code = EmployeeReferenceCode::query()->where('employee_id', $model->id)->findOrFail($referenceCode);

        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(ReferenceCodeRetireReason::values())],
        ], ['reason_code.*' => 'Choose why this code is being retired.']);

        $service->retire($model, $code, ReferenceCodeRetireReason::from($validated['reason_code']), $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }
}
