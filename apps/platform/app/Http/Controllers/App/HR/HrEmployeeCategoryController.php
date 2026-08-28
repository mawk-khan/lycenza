<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Http\Controllers\Controller;
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

class HrEmployeeCategoryController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.categories.view', $school);

        return Inertia::render('App/HR/Categories/Index', [
            'categories' => EmployeeCategory::query()->orderBy('name')->get(['id', 'name', 'code', 'status'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'hr.categories.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, EmployeeCategoryService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.categories.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('employee_categories', 'code')->where('school_id', $school->id)],
        ]);

        $service->create($school, $validated, $context->actor());

        return redirect('/app/hr/categories');
    }

    public function archive(TenantContext $context, EmployeeCategoryService $service, string $category): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.categories.manage', $school);
        abort_if(! Str::isUuid($category), 404);

        $service->archive(EmployeeCategory::query()->findOrFail($category), $context->actor());

        return redirect('/app/hr/categories');
    }

    public function reactivate(TenantContext $context, EmployeeCategoryService $service, string $category): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.categories.manage', $school);
        abort_if(! Str::isUuid($category), 404);

        $service->reactivate(EmployeeCategory::query()->findOrFail($category), $context->actor());

        return redirect('/app/hr/categories');
    }
}
