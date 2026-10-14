<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\SubjectAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $query = Subject::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Subject $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, School $school, SubjectAdministrationService $subjects): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $subject = $subjects->create($school, $request->all(), $request->user());

        return response()->json(['data' => $this->present($subject)], 201);
    }

    public function show(School $school, string $subject): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $model = Subject::query()->findOrFail($subject);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $subject, SubjectAdministrationService $subjects): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = $subjects->update($school, $subject, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'code' => $subject->code,
            'shortName' => $subject->short_name,
            'subjectType' => $subject->subject_type,
            'academicDepartmentId' => $subject->academic_department_id,
            'status' => $subject->status,
        ];
    }
}
