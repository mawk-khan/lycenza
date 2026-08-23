<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Events\GradeLevelCreated;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 0D sections 22-24, 52. `{gradeLevel}` is resolved explicitly
 * (never implicit route-model binding, section 51) so resolution
 * always happens strictly after `school-membership` middleware has set
 * TenantContext -- a School B id then 404s "for free" via SchoolScope.
 */
class GradeLevelController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $query = GradeLevel::query()->orderBy('sequence');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (GradeLevel $g) => $this->present($g))->all()]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('grade_levels')->where('school_id', $school->id)],
            'sequence' => ['required', 'integer', 'min:0', Rule::unique('grade_levels')->where('school_id', $school->id)],
            'education_stage' => ['nullable', 'string', 'max:64'],
        ]);

        $gradeLevel = DB::transaction(function () use ($school, $validated, $request) {
            $gradeLevel = GradeLevel::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'grade_level.created', actor: $request->user(), subject: $gradeLevel, metadata: [
                'name' => $gradeLevel->name,
                'code' => $gradeLevel->code,
            ]);

            event(new GradeLevelCreated($school->id, $gradeLevel->id, $gradeLevel->name, $gradeLevel->code));

            return $gradeLevel;
        });

        return response()->json(['data' => $this->present($gradeLevel)], 201);
    }

    public function show(School $school, string $gradeLevel): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = GradeLevel::query()->findOrFail($gradeLevel);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $gradeLevel): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = GradeLevel::query()->findOrFail($gradeLevel);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('grade_levels')->where('school_id', $school->id)->ignore($model->id)],
            'sequence' => ['sometimes', 'integer', 'min:0', Rule::unique('grade_levels')->where('school_id', $school->id)->ignore($model->id)],
            'education_stage' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'grade_level.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GradeLevel $gradeLevel): array
    {
        return [
            'id' => $gradeLevel->id,
            'name' => $gradeLevel->name,
            'code' => $gradeLevel->code,
            'sequence' => $gradeLevel->sequence,
            'educationStage' => $gradeLevel->education_stage,
            'status' => $gradeLevel->status,
        ];
    }
}
