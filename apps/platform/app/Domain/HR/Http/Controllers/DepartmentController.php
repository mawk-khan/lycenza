<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Infrastructure\Department;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * HR Department reference data. Structurally a top-level School-scoped
 * reference entity, like `AcademicYear`/`Subject` -- not an Employee-
 * nested child -- so this controller follows
 * `AcademicYearController`/`SubjectController`'s established pattern of
 * an inline `authorizeCapability()` check in every action (including
 * `index()`, which has no Application-layer read service to rely on at
 * all), in addition to route-level `capability:` middleware and
 * `DepartmentService`'s own internal check on every mutation. This is a
 * deliberately different choice from the Employee-nested HR controllers
 * (`EmployeeAddressController` etc.), which rely on the Application
 * service's check alone per HR's own documented "no divergent
 * capability matrix" decision (8A.14) -- Department/Position/
 * EmployeeCategory predate that decision's Employee-specific scope and
 * match Academic Structure's reference-entity shape instead.
 */
class DepartmentController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hr.departments.view', $school);

        $query = Department::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Department $d) => $this->present($d))->all()]);
    }

    public function store(Request $request, School $school, DepartmentService $service): JsonResponse
    {
        $this->authorizeCapability('hr.departments.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('hr_departments', 'code')->where('school_id', $school->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'parent_department_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $campus = isset($validated['campus_id'])
            ? Campus::query()->findOrFail($validated['campus_id'])
            : null;
        $parent = isset($validated['parent_department_id'])
            ? Department::query()->findOrFail($validated['parent_department_id'])
            : null;

        $department = $service->create($school, $validated, $request->user(), campus: $campus, parent: $parent);

        return response()->json(['data' => $this->present($department)], 201);
    }

    public function update(Request $request, School $school, string $department, DepartmentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($department), 404);
        $this->authorizeCapability('hr.departments.manage', $school);
        $this->normalizeCodeInput($request);

        $model = Department::query()->findOrFail($department);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('hr_departments', 'code')->where('school_id', $school->id)->ignore($model->id)],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $department, DepartmentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($department), 404);
        $this->authorizeCapability('hr.departments.manage', $school);

        $model = Department::query()->findOrFail($department);
        $archived = $service->archive($model, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    public function reactivate(Request $request, School $school, string $department, DepartmentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($department), 404);
        $this->authorizeCapability('hr.departments.manage', $school);

        $model = Department::query()->findOrFail($department);
        $reactivated = $service->reactivate($model, $request->user());

        return response()->json(['data' => $this->present($reactivated)]);
    }

    public function reparent(Request $request, School $school, string $department, DepartmentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($department), 404);
        $this->authorizeCapability('hr.departments.manage', $school);

        $model = Department::query()->findOrFail($department);

        $validated = $request->validate(['parent_department_id' => ['sometimes', 'nullable', 'uuid']]);

        $newParent = isset($validated['parent_department_id'])
            ? Department::query()->findOrFail($validated['parent_department_id'])
            : null;

        $reparented = $service->reparent($model, $newParent, $request->user());

        return response()->json(['data' => $this->present($reparented)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Department $department): array
    {
        return [
            'id' => $department->id,
            'campusId' => $department->campus_id,
            'parentDepartmentId' => $department->parent_department_id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'status' => $department->status,
        ];
    }
}
