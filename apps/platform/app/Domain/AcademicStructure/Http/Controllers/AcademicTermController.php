<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicTermController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

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

        $year = AcademicYear::query()->findOrFail($academicYear);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('academic_terms')->where('school_id', $school->id)->where('academic_year_id', $year->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'sequence' => ['required', 'integer', 'min:1'],
        ]);

        $term = $service->create($year, $validated, $request->user());

        return response()->json(['data' => $this->present($term)], 201);
    }

    public function show(School $school, string $academicTerm): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $model = AcademicTerm::query()->findOrFail($academicTerm);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicTerm): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        $model = AcademicTerm::query()->findOrFail($academicTerm);
        $this->normalizeCodeInput($request);

        // Renaming only -- changing dates/sequence would bypass the
        // range/overlap invariants AcademicTermService enforces at
        // creation time; a future checkpoint can add a dedicated
        // reschedule path if a real need emerges.
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_terms')->where('school_id', $school->id)->where('academic_year_id', $model->academic_year_id)->ignore($model->id)],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'academic_term.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
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
