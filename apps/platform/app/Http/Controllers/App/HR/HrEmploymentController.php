<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmployeeCategoryNotFoundException;
use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrEmploymentController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, EmploymentService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.assignments.manage` floor, which a Campus grant never satisfies.
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', Rule::in(EmploymentType::values())],
            'staff_type' => ['required', 'string', Rule::in(StaffType::values())],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_on' => ['required', 'date'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $service->create($model, $validated, $context->actor());
        } catch (EmployeeCategoryNotFoundException) {
            throw ValidationException::withMessages(['employee_category_id' => ['That Employee Category was not found.']]);
        }

        return redirect("/app/hr/employees/{$model->id}");
    }

    /**
     * HR.1A (S-8): ending through the web is the separation command -- a
     * future date is refused exactly as separate() refuses it; the permissive
     * EmploymentService::end() primitive is not reachable over HTTP.
     */
    public function end(Request $request, TenantContext $context, EmployeeLifecycleService $service, string $employee, string $employment): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.assignments.manage` floor, which a Campus grant never satisfies.
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $service->separate($employmentModel, $validated['ends_on'], $context->actor(), $validated['status'] ?? 'separated');

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    /**
     * HR.1A (X-3): a dated, reasoned change of an employment's terms --
     * appended, never overwritten (EmploymentTermsService). A declared
     * whole-School operation (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS).
     */
    public function changeTerms(Request $request, TenantContext $context, EmploymentTermsService $service, string $employee, string $employment): RedirectResponse
    {
        $context->requireSchool();
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->where('employee_id', $employeeModel->id)->findOrFail($employment);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'employment_type' => ['required', 'string', Rule::in(EmploymentType::values())],
            'staff_type' => ['required', 'string', Rule::in(StaffType::values())],
            'employee_category_id' => ['sometimes', 'nullable', 'uuid'],
            'reason_code' => ['required', 'string', Rule::in(EmploymentTermsChangeReason::values())],
        ]);

        try {
            $service->change(
                $employmentModel,
                (string) $validated['effective_from'],
                EmploymentType::from((string) $validated['employment_type']),
                StaffType::from((string) $validated['staff_type']),
                isset($validated['employee_category_id']) && $validated['employee_category_id'] !== '' ? (string) $validated['employee_category_id'] : null,
                EmploymentTermsChangeReason::from((string) $validated['reason_code']),
                $context->actor(),
            );
        } catch (EmployeeCategoryNotFoundException) {
            throw ValidationException::withMessages(['employee_category_id' => ['That Employee Category was not found.']]);
        }

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function separate(Request $request, TenantContext $context, EmployeeLifecycleService $service, string $employee, string $employment): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.assignments.manage` floor, which a Campus grant never satisfies.
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($employment), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $employmentModel = EmploymentRecord::query()->findOrFail($employment);

        $validated = $request->validate([
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['separated', 'terminated', 'retired', 'deceased'])],
        ]);

        $service->separate($employmentModel, $validated['ends_on'], $context->actor(), $validated['status'] ?? 'separated');

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    public function rehire(Request $request, TenantContext $context, EmployeeLifecycleService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.assignments.manage` floor, which a Campus grant never satisfies.
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'employment_type' => ['required', 'string', Rule::in(EmploymentType::values())],
            'staff_type' => ['required', 'string', Rule::in(StaffType::values())],
            'starts_on' => ['required', 'date'],
        ]);

        $service->rehire($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }
}
