<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3, alongside item 4's
 * EmployeeCategory entity build-out) -- HTTP mutation transport for
 * EmployeeCategory reference data. Same reference-entity controller
 * shape as `DepartmentController`/`PositionController` -- see
 * `DepartmentController`'s docblock.
 */
class EmployeeCategoryController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hr.categories.view', $school);

        $query = EmployeeCategory::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (EmployeeCategory $c) => $this->present($c))->all()]);
    }

    public function store(Request $request, School $school, EmployeeCategoryService $service): JsonResponse
    {
        $this->authorizeCapability('hr.categories.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('employee_categories', 'code')->where('school_id', $school->id)],
        ]);

        $category = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(Request $request, School $school, string $category, EmployeeCategoryService $service): JsonResponse
    {
        abort_if(! Str::isUuid($category), 404);
        $this->authorizeCapability('hr.categories.manage', $school);
        $this->normalizeCodeInput($request);

        $model = EmployeeCategory::query()->findOrFail($category);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('employee_categories', 'code')->where('school_id', $school->id)->ignore($model->id)],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $category, EmployeeCategoryService $service): JsonResponse
    {
        abort_if(! Str::isUuid($category), 404);
        $this->authorizeCapability('hr.categories.manage', $school);

        $model = EmployeeCategory::query()->findOrFail($category);
        $archived = $service->archive($model, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    public function reactivate(Request $request, School $school, string $category, EmployeeCategoryService $service): JsonResponse
    {
        abort_if(! Str::isUuid($category), 404);
        $this->authorizeCapability('hr.categories.manage', $school);

        $model = EmployeeCategory::query()->findOrFail($category);
        $reactivated = $service->reactivate($model, $request->user());

        return response()->json(['data' => $this->present($reactivated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'code' => $category->code,
            'status' => $category->status,
        ];
    }
}
