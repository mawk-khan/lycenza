<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\AcademicDepartmentAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D section 32/52. Deliberately no domain event for
 * AcademicDepartment (section 43: "do not emit events for every
 * trivial field read/write" -- this is the least consequential Phase
 * 0D entity, and no future module is known to react to it yet).
 */
class AcademicDepartmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $query = AcademicDepartment::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (AcademicDepartment $d) => $this->present($d))->all()]);
    }

    public function store(Request $request, School $school, AcademicDepartmentAdministrationService $departments): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $department = $departments->create($school, $request->all(), $request->user());

        return response()->json(['data' => $this->present($department)], 201);
    }

    public function show(School $school, string $academicDepartment): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = AcademicDepartment::query()->findOrFail($academicDepartment);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicDepartment, AcademicDepartmentAdministrationService $departments): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $departments->update($school, $academicDepartment, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
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
