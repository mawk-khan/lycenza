<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeDirectoryEntry;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\DuplicateWorkEmailException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 8A closure correction (item 2) -- session-authenticated Inertia
 * pages for Employee administration, mirroring
 * `App\Http\Controllers\App\StudentController`'s established shape.
 * Deliberately consumes the SAME Application-layer boundaries the JSON
 * API controllers use (`EmployeeDirectoryService`,
 * `EmployeeProfileWorkspaceService`, `EmployeeService`) rather than
 * building a parallel query -- no HR business rule is re-derived here
 * (root CLAUDE.md rule 3 and this correction's item 2 mandate).
 *
 * Domain exceptions a user can plausibly trigger through a form are
 * translated to `ValidationException::withMessages()` here, exactly
 * like `StudentController`'s `DuplicateStudentNumberException`
 * handling -- Inertia's client-side `form.errors` only recognizes
 * Laravel's own validation exception shape.
 */
class HrEmployeeController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, EmployeeDirectoryService $service): Response
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'include_archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new EmployeeDirectoryQuery(
            search: $validated['search'] ?? null,
            includeArchived: $request->boolean('include_archived'),
            page: (int) ($validated['page'] ?? 1),
        );

        $paginator = $service->search($school, $query, $context->actor());

        return Inertia::render('App/HR/Employees/Index', [
            'employees' => $paginator->through(fn (EmployeeDirectoryEntry $e) => $e->toArray())->withQueryString(),
            'filters' => [
                'search' => $validated['search'] ?? '',
                'include_archived' => $request->boolean('include_archived'),
            ],
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'hr.employees.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);

        return Inertia::render('App/HR/Employees/Create');
    }

    public function store(Request $request, TenantContext $context, EmployeeService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $employee = $service->create($school, $validated, $context->actor());
        } catch (DuplicateWorkEmailException) {
            throw ValidationException::withMessages([
                'work_email' => ['This work email is already in use by another Employee in this School.'],
            ]);
        }

        return redirect("/app/hr/employees/{$employee->id}");
    }

    public function show(TenantContext $context, EmployeeProfileWorkspaceService $workspaceService, CapabilityResolver $capabilities, string $employee): Response
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $workspace = $workspaceService->build($school, $employee, $context->actor());
        abort_if($workspace === null, 404);

        $actor = $context->actor();
        $canManageAssignments = $capabilities->canInSchool($actor, 'hr.employees.assignments.manage', $school);

        return Inertia::render('App/HR/Employees/Show', [
            'workspace' => $workspace->toArray(),
            'can' => [
                'manageEmployees' => $capabilities->canInSchool($actor, 'hr.employees.manage', $school),
                'managePersonal' => $capabilities->canInSchool($actor, 'hr.employees.personal.manage', $school),
                'manageAssignments' => $canManageAssignments,
                'manageQualifications' => $capabilities->canInSchool($actor, 'hr.employees.qualifications.manage', $school),
                'manageDocuments' => $capabilities->canInSchool($actor, 'hr.employees.documents.manage', $school),
                'manageNotes' => $capabilities->canInSchool($actor, 'hr.employees.notes.manage', $school),
            ],
            // Reference-data pickers for the inline Assignment form --
            // only fetched when the actor could actually use them,
            // matching the workspace's own "absent when not needed"
            // principle for optional props.
            'positions' => ! $canManageAssignments ? [] : Position::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => ! $canManageAssignments ? [] : Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            'employeeCategories' => ! $canManageAssignments ? [] : EmployeeCategory::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function edit(TenantContext $context, string $employee): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        return Inertia::render('App/HR/Employees/Edit', [
            'employee' => [
                'id' => $model->id,
                'employeeNumber' => $model->employee_number,
                'fullName' => $model->full_name,
                'workEmail' => $model->work_email,
                'workPhone' => $model->work_phone,
            ],
        ]);
    }

    public function update(Request $request, TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $service->update($model, $validated, $context->actor());
        } catch (DuplicateWorkEmailException) {
            throw ValidationException::withMessages([
                'work_email' => ['This work email is already in use by another Employee in this School.'],
            ]);
        } catch (UnrelatedUserLinkageException) {
            throw ValidationException::withMessages([
                'user_id' => ['That User has no active membership at this School.'],
            ]);
        }

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function archive(TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);
        $service->archive($model, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function restore(TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);
        $service->restore($model, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }
}
