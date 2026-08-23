<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0D sections 13-18, 52, 55: thin controller -- lifecycle logic
 * (overlap checks, the transactional/concurrency-safe activate/close
 * transitions) lives entirely in AcademicYearService.
 */
class AcademicYearController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $query = AcademicYear::query()->orderByDesc('starts_on');

        if (! $request->boolean('include_archived')) {
            $query->whereIn('status', ['draft', 'active', 'closed']);
        }

        return response()->json(['data' => $query->get()->map(fn (AcademicYear $y) => $this->present($y))->all()]);
    }

    public function store(Request $request, School $school, AcademicYearService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('academic_years')->where('school_id', $school->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $year = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($year)], 201);
    }

    public function show(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $model = AcademicYear::query()->findOrFail($academicYear);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        $model = AcademicYear::query()->findOrFail($academicYear);
        $this->normalizeCodeInput($request);

        // Only the label/code are editable here -- date-range and
        // status changes go through dedicated, invariant-checked paths
        // (dates would silently invalidate the overlap check already
        // performed at creation; status changes go through
        // activate()/close() below).
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_years')->where('school_id', $school->id)->ignore($model->id)],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'academic_year.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    public function activate(Request $request, School $school, string $academicYear, AcademicYearService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        $model = AcademicYear::query()->findOrFail($academicYear);
        $activated = $service->activate($model, $request->user());

        return response()->json(['data' => $this->present($activated)]);
    }

    public function close(Request $request, School $school, string $academicYear, AcademicYearService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        $model = AcademicYear::query()->findOrFail($academicYear);
        $closed = $service->close($model, $request->user());

        return response()->json(['data' => $this->present($closed)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AcademicYear $year): array
    {
        return [
            'id' => $year->id,
            'name' => $year->name,
            'code' => $year->code,
            'startsOn' => $year->starts_on->toDateString(),
            'endsOn' => $year->ends_on->toDateString(),
            'status' => $year->status,
        ];
    }
}
