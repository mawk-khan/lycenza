<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 37-40. Deliberately NOT attached to Section --
 * offered to a (AcademicYear, Campus, GradeLevel), inherited by that
 * Grade's Sections (see docs/modules/ACADEMIC-STRUCTURE.md).
 */
class SubjectOfferingController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $offerings = SubjectOffering::query()->where('academic_year_id', $year->id)->get();

        return response()->json(['data' => $offerings->map(fn (SubjectOffering $o) => $this->present($o))->all()]);
    }

    public function store(Request $request, School $school, string $academicYear, SubjectOfferingService $offerings): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $offering = $offerings->create($school, $academicYear, $request->all(), $request->user());

        return response()->json(['data' => $this->present($offering)], 201);
    }

    public function show(School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $model = SubjectOffering::query()->findOrFail($subjectOffering);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $subjectOffering, SubjectOfferingService $offerings): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        // ADR 0069 (S1): the classification freeze is enforced there (409 SUBJECT_OFFERING_CLASSIFICATION_LOCKED).
        // Only the mutable settings are passed on -- never an identity column
        // (Attendance's subject provenance relies on a stable Offering).
        $model = $offerings->updateFromInput($school, $subjectOffering, $request->only(SubjectOfferingService::MUTABLE_FIELDS), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'academicYearId' => $offering->academic_year_id,
            'campusId' => $offering->campus_id,
            'gradeLevelId' => $offering->grade_level_id,
            'subjectId' => $offering->subject_id,
            'isRequired' => $offering->is_required,
            'sequence' => $offering->sequence,
            'weeklyPeriodsTarget' => $offering->weekly_periods_target,
            'status' => $offering->status,
        ];
    }
}
