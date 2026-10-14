<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicTermController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $terms = AcademicTerm::query()->where('academic_year_id', $year->id)->orderBy('sequence')->get();

        return response()->json(['data' => $terms->map(fn (AcademicTerm $t) => $this->present($t))->all()]);
    }

    public function store(Request $request, School $school, string $academicYear, AcademicTermService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        $term = $service->createFromInput($school, $academicYear, $request->all(), $request->user());

        return response()->json(['data' => $this->present($term)], 201);
    }

    public function show(School $school, string $academicTerm): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $model = AcademicTerm::query()->findOrFail($academicTerm);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicTerm, AcademicTermService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        // Renaming only -- dates/sequence would bypass the range/overlap
        // invariants AcademicTermService enforces at creation time.
        $model = $service->rename($school, $academicTerm, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AcademicTerm $term): array
    {
        return [
            'id' => $term->id,
            'academicYearId' => $term->academic_year_id,
            'name' => $term->name,
            'code' => $term->code,
            'startsOn' => $term->starts_on->toDateString(),
            'endsOn' => $term->ends_on->toDateString(),
            'sequence' => $term->sequence,
        ];
    }
}
