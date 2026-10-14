<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\GradeLevelAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 22-24, 52. `{gradeLevel}` is resolved explicitly
 * (never implicit route-model binding, section 51) so resolution
 * always happens strictly after `school-membership` middleware has set
 * TenantContext -- a School B id then 404s "for free" via SchoolScope.
 */
class GradeLevelController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $query = GradeLevel::query()->orderBy('sequence');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (GradeLevel $g) => $this->present($g))->all()]);
    }

    public function store(Request $request, School $school, GradeLevelAdministrationService $gradeLevels): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $gradeLevel = $gradeLevels->create($school, $request->all(), $request->user());

        return response()->json(['data' => $this->present($gradeLevel)], 201);
    }

    public function show(School $school, string $gradeLevel): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = GradeLevel::query()->findOrFail($gradeLevel);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $gradeLevel, GradeLevelAdministrationService $gradeLevels): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $gradeLevels->update($school, $gradeLevel, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
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
