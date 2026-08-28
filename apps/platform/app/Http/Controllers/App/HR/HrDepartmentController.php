<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Infrastructure\Department;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HrDepartmentController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.departments.view', $school);

        return Inertia::render('App/HR/Departments/Index', [
            'departments' => Department::query()->with('parent:id,name')->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'code' => $d->code,
                    'status' => $d->status,
                    'campusId' => $d->campus_id,
                    'parentDepartmentId' => $d->parent_department_id,
                    'parentName' => $d->parent?->name,
                ])->all(),
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'hr.departments.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, DepartmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.departments.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('hr_departments', 'code')->where('school_id', $school->id)],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'parent_department_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $campus = isset($validated['campus_id']) ? Campus::query()->findOrFail($validated['campus_id']) : null;
        $parent = isset($validated['parent_department_id']) ? Department::query()->findOrFail($validated['parent_department_id']) : null;

        $service->create($school, $validated, $context->actor(), campus: $campus, parent: $parent);

        return redirect('/app/hr/departments');
    }

    public function archive(TenantContext $context, DepartmentService $service, string $department): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.departments.manage', $school);
        abort_if(! Str::isUuid($department), 404);

        $service->archive(Department::query()->findOrFail($department), $context->actor());

        return redirect('/app/hr/departments');
    }

    public function reactivate(TenantContext $context, DepartmentService $service, string $department): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.departments.manage', $school);
        abort_if(! Str::isUuid($department), 404);

        $service->reactivate(Department::query()->findOrFail($department), $context->actor());

        return redirect('/app/hr/departments');
    }
}
