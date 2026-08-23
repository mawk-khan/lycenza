<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0D section 32/52. Deliberately no domain event for
 * AcademicDepartment (section 43: "do not emit events for every
 * trivial field read/write" -- this is the least consequential Phase
 * 0D entity, and no future module is known to react to it yet).
 */
class AcademicDepartmentController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $query = AcademicDepartment::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (AcademicDepartment $d) => $this->present($d))->all()]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('academic_departments')->where('school_id', $school->id)],
        ]);

        $department = AcademicDepartment::query()->create([...$validated, 'school_id' => $school->id]);

        app(AuditRecorder::class)->school($school, 'academic_department.created', actor: $request->user(), subject: $department, metadata: $validated);

        return response()->json(['data' => $this->present($department)], 201);
    }

    public function show(School $school, string $academicDepartment): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = AcademicDepartment::query()->findOrFail($academicDepartment);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicDepartment): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = AcademicDepartment::query()->findOrFail($academicDepartment);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_departments')->where('school_id', $school->id)->ignore($model->id)],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'academic_department.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AcademicDepartment $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
        ];
    }
}
