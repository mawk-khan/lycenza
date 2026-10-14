<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 13-18, 52, 55: thin controller -- lifecycle logic
 * (overlap checks, the transactional/concurrency-safe activate/close
 * transitions) lives entirely in AcademicYearService.
 */
class AcademicYearController extends Controller
{
    use AuthorizesCapability;

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

        $year = $service->createFromInput($school, $request->all(), $request->user());

        return response()->json(['data' => $this->present($year)], 201);
    }

    public function show(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.years.view', $school);

        $model = AcademicYear::query()->findOrFail($academicYear);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $academicYear, AcademicYearService $service): JsonResponse
    {
        $this->authorizeCapability('academics.years.manage', $school);

        // Only the label/code are editable here -- date-range and status
        // changes go through dedicated, invariant-checked paths.
        $model = $service->rename($school, $academicYear, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
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
