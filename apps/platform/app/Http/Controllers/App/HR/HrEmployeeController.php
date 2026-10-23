<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeDirectoryEntry;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeOnboardingData;
use App\Domain\HR\Application\EmployeeOnboardingService;
use App\Domain\HR\Application\EmployeeOperationalOverviewService;
use App\Domain\HR\Application\EmployeePickerReadService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\DuplicateWorkEmailException;
use App\Domain\HR\Application\Exceptions\EmployeeNumberConflictException;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Exceptions\HrWholeSchoolAuthorityRequiredException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\StaffNavigation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
 *
 * ORG.7E-1 (ADR 0076 §43): the Directory, the operational overview, core
 * edit and onboarding are Campus-adopted (HrEmployeeAccess); identity-only
 * create, archive and restore are declared whole-School operations (their
 * routes carry the ordinary `capability:hr.employees.manage` floor); the
 * Profile stays behind whole-School `hr.employees.personal.view`.
 */
class HrEmployeeController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, EmployeeDirectoryService $service, HrEmployeeAccess $access): Response
    {
        $school = $context->requireSchool();
        // ORG.7E-1: restricted in SQL to the actor's Campuses (unchanged for the whole School).
        $scope = $this->authorizeCampusScope('hr.employees.view', $school);

        // V11.2 (ADR 0075): the web directory now passes the filters
        // EmployeeDirectoryService already supports (and the /api/v1
        // directory already exposes) -- campus, department, position and
        // the allow-listed sort. Format is checked here because the
        // service compares UUID columns (a non-UUID would be a 500, see
        // the API controller); EmployeeDirectoryQuery stays the sort
        // allow-list authority.
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'position_id' => ['sometimes', 'nullable', 'uuid'],
            'sort' => ['sometimes', 'nullable', Rule::in(EmployeeDirectoryQuery::ALLOWED_SORTS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'include_archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // ORG.9C-1 (ADR 0076 §69): HR-040/044/045/056 -- narrowing filters only.
            'employment' => ['sometimes', 'nullable', Rule::in(array_keys(EmployeeDirectoryQuery::EMPLOYMENT_FILTERS))],
            'several_campuses' => ['sometimes', 'boolean'],
            'joined_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'joined_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:joined_from'],
            'manager_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $query = new EmployeeDirectoryQuery(
            search: $validated['search'] ?? null,
            campusId: $validated['campus_id'] ?? null,
            departmentId: $validated['department_id'] ?? null,
            positionId: $validated['position_id'] ?? null,
            includeArchived: $request->boolean('include_archived'),
            sort: $validated['sort'] ?? null,
            direction: $validated['direction'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            employment: $validated['employment'] ?? null,
            severalCampuses: $request->boolean('several_campuses'),
            joinedFrom: $validated['joined_from'] ?? null,
            joinedTo: $validated['joined_to'] ?? null,
            managerEmployeeId: $validated['manager_id'] ?? null,
        );

        $paginator = $service->search($school, $query, $context->actor());

        $capabilities = app(CapabilityResolver::class);
        $actor = $context->actor();

        return Inertia::render('App/HR/Employees/Index', [
            'employees' => $paginator->through(fn (EmployeeDirectoryEntry $e) => $e->toArray())->withQueryString(),
            'filters' => [
                'search' => $validated['search'] ?? '',
                'campus_id' => $validated['campus_id'] ?? '',
                'department_id' => $validated['department_id'] ?? '',
                'position_id' => $validated['position_id'] ?? '',
                'sort' => $query->sort,
                'direction' => $query->direction,
                'include_archived' => $request->boolean('include_archived'),
                'employment' => $validated['employment'] ?? '',
                'several_campuses' => $request->boolean('several_campuses'),
                'joined_from' => $validated['joined_from'] ?? '',
                'joined_to' => $validated['joined_to'] ?? '',
                'manager_id' => $validated['manager_id'] ?? '',
            ],
            // Filter options. Campuses are School setup reference data
            // every directory reader already sees in the rows. Department
            // and Position lists are offered only to holders of their
            // own view capability (the HR organisation pages' gates), so
            // the directory never becomes a wider route to the org
            // structure than those pages.
            // ORG.7E-1: a Campus-scoped reader is offered only their own Campuses.
            'campusOptions' => $access->campusOptions($school, $scope),
            'departmentOptions' => $capabilities->canInSchool($actor, 'hr.departments.view', $school)
                ? Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->values()->all()
                : null,
            'positionOptions' => $capabilities->canInSchool($actor, 'hr.positions.view', $school)
                ? Position::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Position $p) => ['id' => $p->id, 'name' => $p->name])->values()->all()
                : null,
            // Identity-only create is whole-School; onboarding needs both
            // operational scopes; the Profile needs whole-School personal.view.
            'canManage' => $access->holdsWholeSchool($actor, 'hr.employees.manage', $school),
            'canOnboard' => self::canOnboard($access, $actor, $school),
            'canViewProfile' => $capabilities->canInSchool($actor, 'hr.employees.personal.view', $school),
            // The HR hub is whole-School (CampusScopeCatalogue::WHOLE_SCHOOL_HUBS).
            'canOpenHub' => collect(StaffNavigation::hubRequirement('hr'))->contains(fn (string $c) => $capabilities->canInSchool($actor, $c, $school)),
        ]);
    }

    // ORG.7E-1: authorized by the route's whole-School `capability:hr.employees.manage`
    // floor (an identity-only Employee has no Campus; a declared operation).
    public function create(TenantContext $context): Response
    {
        $context->requireSchool();

        return Inertia::render('App/HR/Employees/Create');
    }

    // ORG.7E-1: the route's whole-School `capability:hr.employees.manage` floor.
    public function store(Request $request, TenantContext $context, EmployeeService $service): RedirectResponse
    {
        $school = $context->requireSchool();

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
        } catch (EmployeeNumberConflictException $e) {
            // UAT-HR-20b: a refusal on the form, never a 500.
            throw ValidationException::withMessages(['employee' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$employee->id}");
    }

    public function show(TenantContext $context, EmployeeProfileWorkspaceService $workspaceService, CapabilityResolver $capabilities, HrEmployeeAccess $access, string $employee): Response
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $workspace = $workspaceService->build($school, $employee, $context->actor());
        abort_if($workspace === null, 404);

        $actor = $context->actor();
        // ORG.7E-1: the Profile is whole-School, so are its actions (a Campus
        // grant acts from the operational overview instead).
        $canManageAssignments = $access->holdsWholeSchool($actor, 'hr.employees.assignments.manage', $school);
        $canManageEmployees = $access->holdsWholeSchool($actor, 'hr.employees.manage', $school);

        return Inertia::render('App/HR/Employees/Show', [
            'workspace' => $workspace->toArray(),
            'can' => [
                'manageEmployees' => $canManageEmployees,
                'managePersonal' => $capabilities->canInSchool($actor, 'hr.employees.personal.manage', $school),
                'manageAssignments' => $canManageAssignments,
                'manageQualifications' => $capabilities->canInSchool($actor, 'hr.employees.qualifications.manage', $school),
                'manageDocuments' => $capabilities->canInSchool($actor, 'hr.employees.documents.manage', $school),
                'manageNotes' => $capabilities->canInSchool($actor, 'hr.employees.notes.manage', $school),
                // V11.2: the link to this Employee's Teaching Assignments
                // (a filtered view of the TeachingAssignments list, which
                // re-checks the same capability itself). The catalogue key,
                // not a TeachingAssignments class: that module depends on
                // HR, never the reverse (DOMAIN-MAP, CLAUDE.md rule 4).
                'viewTeachingAssignments' => $capabilities->canInSchool($actor, 'teaching.assignments.view', $school),
            ],
            // Reference-data pickers for the inline Assignment form --
            // only fetched when the actor could actually use them,
            // matching the workspace's own "absent when not needed"
            // principle for optional props.
            'positions' => ! $canManageAssignments ? [] : Position::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => ! $canManageAssignments ? [] : Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            'employeeCategories' => ! $canManageAssignments ? [] : EmployeeCategory::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            // SR.4 (ADR 0071 §26.7): registering a highly sensitive record needs a fresh code.
            'hasMfaFactor' => app(FreshMfaRequirement::class)->hasActiveFactor($actor),
            // UAT-C6 (UAT-STF-05): the linked sign-in account, for an HR manager only.
            'linkedAccountName' => $canManageEmployees
                ? app(EmployeePickerReadService::class)->linkedAccountName($school, $access->employee($school, $access->scopeOf($actor, 'hr.employees.manage', $school), $employee), $actor)
                : null,
        ]);
    }

    /**
     * ORG.7E-1 (ADR 0076 §43): the Campus-safe operational overview -- a
     * minimized read model for Directory holders who are not (or not
     * whole-School) Profile readers. In-scope placement and current
     * assignments only; never personal, document, note or pay data.
     */
    public function overview(TenantContext $context, EmployeeOperationalOverviewService $overviews, HrEmployeeAccess $access, CapabilityResolver $capabilities, string $employee): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hr.employees.view', $school);
        abort_if(! Str::isUuid($employee), 404);
        $actor = $context->actor();

        $model = $access->employee($school, $scope, $employee);
        $manage = $access->scopeOf($actor, 'hr.employees.manage', $school);
        $assign = $access->scopeOf($actor, 'hr.employees.assignments.manage', $school);
        $canManageAssignments = ! $assign->isNone();
        $currentEmployment = $canManageAssignments ? $access->currentEmployment($school, $assign, $model->id) : null;
        $canAddAssignment = $currentEmployment !== null && $access->coversAllCurrentCampuses($school, $assign, $model->id);

        return Inertia::render('App/HR/Employees/Overview', [
            'overview' => $overviews->forEmployee($school, $model->id, $actor),
            'can' => [
                'edit' => ! $manage->isNone() && $access->coversAllCurrentCampuses($school, $manage, $model->id),
                'manageAssignments' => $canManageAssignments,
                'addAssignment' => $canAddAssignment,
                'viewProfile' => $capabilities->canInSchool($actor, 'hr.employees.personal.view', $school),
            ],
            // Read-only reference labels for the assignment form (never configuration authority).
            'currentEmploymentId' => $canAddAssignment ? $currentEmployment->id : null,
            'positions' => ! $canAddAssignment ? [] : Position::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => ! $canAddAssignment ? [] : $access->departmentOptions($school, $assign),
            'campuses' => ! $canAddAssignment ? [] : $access->campusOptions($school, $assign),
        ]);
    }

    public function edit(TenantContext $context, HrEmployeeAccess $access, CapabilityResolver $capabilities, string $employee): Response
    {
        $school = $context->requireSchool();
        // ORG.7E-1: a Campus-scoped editor reaches only an Employee in scope, and
        // edits School-wide identity only with EVERY current Campus in scope.
        $scope = $this->authorizeCampusScope('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $scope, $employee);
        if (! $access->coversAllCurrentCampuses($school, $scope, $model->id)) {
            throw new HrWholeSchoolAuthorityRequiredException;
        }

        return Inertia::render('App/HR/Employees/Edit', [
            'backHref' => $capabilities->canInSchool($context->actor(), 'hr.employees.personal.view', $school)
                ? "/app/hr/employees/{$model->id}" : "/app/hr/employees/{$model->id}/overview",
            'employee' => [
                'id' => $model->id,
                'employeeNumber' => $model->employee_number,
                'fullName' => $model->full_name,
                'workEmail' => $model->work_email,
                'workPhone' => $model->work_phone,
            ],
        ]);
    }

    public function update(Request $request, TenantContext $context, EmployeeService $service, HrEmployeeAccess $access, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $scope, $employee);

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
        } catch (HrWholeSchoolAuthorityRequiredException $e) {
            throw ValidationException::withMessages(['employee' => [$e->getMessage()]]);
        }

        return redirect(self::employeePage($scope->isWholeSchool(), $model->id));
    }

    // ORG.7E-1: the route's whole-School `capability:hr.employees.manage` floor (declared operation).
    public function archive(TenantContext $context, EmployeeService $service, HrEmployeeAccess $access, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($context->actor(), 'hr.employees.manage', $school), $employee);
        $service->archive($model, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    // ORG.7E-1: the route's whole-School `capability:hr.employees.manage` floor (declared operation).
    public function restore(TenantContext $context, EmployeeService $service, HrEmployeeAccess $access, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($context->actor(), 'hr.employees.manage', $school), $employee);
        $service->restore($model, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    /**
     * ORG.7E-1 (ADR 0076 §43): the named onboarding form -- core fields and
     * the first placement on one of the actor's Campuses.
     */
    public function onboard(TenantContext $context, HrEmployeeAccess $access): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('hr.employees.manage', $school);
        $assign = $this->authorizeCampusScope('hr.employees.assignments.manage', $school);

        return Inertia::render('App/HR/Employees/Onboard', [
            'campuses' => $access->campusOptions($school, $assign),
            'departments' => $access->departmentOptions($school, $assign),
            'positions' => Position::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function storeOnboarding(Request $request, TenantContext $context, EmployeeOnboardingService $onboarding): RedirectResponse
    {
        $school = $context->requireSchool();
        $manage = $this->authorizeCampusScope('hr.employees.manage', $school);
        $this->authorizeCampusScope('hr.employees.assignments.manage', $school);

        $validated = $request->validate(EmployeeOnboardingData::rules());

        try {
            $employee = $onboarding->onboard($school, EmployeeOnboardingData::fromValidated($validated), $context->actor());
        } catch (DuplicateWorkEmailException) {
            throw ValidationException::withMessages(['work_email' => ['This work email is already in use by another Employee in this School.']]);
        } catch (HrException $e) {
            throw ValidationException::withMessages(['employee' => [$e->getMessage()]]);
        }

        return redirect(self::employeePage($manage->isWholeSchool(), $employee->id));
    }

    private static function canOnboard(HrEmployeeAccess $access, User $actor, School $school): bool
    {
        return ! $access->scopeOf($actor, 'hr.employees.manage', $school)->isNone()
            && ! $access->scopeOf($actor, 'hr.employees.assignments.manage', $school)->isNone();
    }

    /** Where to land after a write: the Profile for a whole-School actor, the overview for a Campus-scoped one. */
    private static function employeePage(bool $wholeSchool, string $employeeId): string
    {
        return $wholeSchool ? "/app/hr/employees/{$employeeId}" : "/app/hr/employees/{$employeeId}/overview";
    }
}
