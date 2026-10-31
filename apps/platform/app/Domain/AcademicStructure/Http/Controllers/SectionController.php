<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\SectionAdministrationService;
use App\Domain\AcademicStructure\Application\SectionVersion;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 25-28, 36. Nested under a specific AcademicYear
 * for listing/creation -- a Section belongs to exactly one year
 * (historical-safety rule, section 28). Uniqueness across (School,
 * AcademicYear, Campus, GradeLevel, code) and the composite FKs
 * protecting cross-School references are database-enforced (the
 * migration), not re-checked here.
 */
class SectionController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $sections = Section::query()->where('academic_year_id', $year->id)->orderBy('name')->get();

        return response()->json(['data' => $sections->map(fn (Section $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, School $school, string $academicYear, SectionAdministrationService $sections): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $section = $sections->create($school, $academicYear, $request->all(), $request->user());

        return response()->json(['data' => $this->present($section)], 201);
    }

    public function show(School $school, string $section): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = Section::query()->findOrFail($section);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $section, SectionAdministrationService $sections): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $sections->update($school, $section, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Section $section): array
    {
        return [
            'id' => $section->id,
            'academicYearId' => $section->academic_year_id,
            'campusId' => $section->campus_id,
            'gradeLevelId' => $section->grade_level_id,
            'name' => $section->name,
            'code' => $section->code,
            'capacity' => $section->capacity,
            'status' => $section->status,
            // SIS 11.H1: required back on PATCH (stale-update protection).
            'version' => SectionVersion::of($section),
        ];
    }
}
