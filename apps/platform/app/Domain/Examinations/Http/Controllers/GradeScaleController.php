<?php

namespace App\Domain\Examinations\Http\Controllers;

use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Phase 0H.4C -- the GradeScale administrative API. Exactly SEVEN
 * operations: list/create/show/update the scale, plus create/update/
 * delete a band. School-only -- no Examination/ExaminationPaper nesting
 * of any kind (GradeScale is independent of the Examination chain).
 *
 * Thin by construction: authorize -> validate -> delegate to
 * App\Domain\Examinations\Application\GradeScaleService -> present.
 * Every invariant (lifecycle legality, completeness, band immutability,
 * duplicate translation), the parent-row lock, the transaction and
 * every audit write live in the service; this class performs no
 * GradeScale/GradeBand write of its own.
 *
 * `status` is NEVER a generic mass-assignable field here: it is passed
 * through unchanged to the service, which is solely responsible for
 * interpreting it as a guarded lifecycle transition.
 *
 * No DELETE on GradeScale. GradeBand DELETE is the only delete route,
 * and is rejected by the service unless the parent is `draft`.
 */
class GradeScaleController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(School $school): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.view', $school);

        $scales = GradeScale::query()->with('bands')->orderBy('code')->get();

        return response()->json([
            'data' => $scales->map(fn (GradeScale $s) => $this->present($s))->all(),
        ]);
    }

    public function store(Request $request, School $school, GradeScaleService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', $this->uniqueCodeRule($school)],
            'name' => ['required', 'string', 'max:255'],
            'bands' => ['sometimes', 'array'],
            'bands.*.min_percentage' => ['required_with:bands', 'numeric', 'between:0,100'],
            'bands.*.label' => ['required_with:bands', 'string', 'max:32'],
        ]);

        $scale = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($scale)], 201);
    }

    public function show(School $school, string $gradeScale): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.view', $school);

        $model = GradeScale::query()->with('bands')->findOrFail($gradeScale);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $gradeScale, GradeScaleService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(GradeScale::STATUSES)],
        ]);

        $updated = $service->update($school, $model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated->load('bands'))]);
    }

    public function storeBand(Request $request, School $school, string $gradeScale, GradeScaleService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);

        $validated = $request->validate([
            'min_percentage' => ['required', 'numeric', 'between:0,100'],
            'label' => ['required', 'string', 'max:32'],
        ]);

        $band = $service->addBand($school, $model, $validated, $request->user());

        return response()->json(['data' => $this->presentBand($band)], 201);
    }

    public function updateBand(Request $request, School $school, string $gradeScale, string $gradeBand, GradeScaleService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);
        $band = GradeBand::query()->where('grade_scale_id', $model->id)->findOrFail($gradeBand);

        $validated = $request->validate([
            'min_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'label' => ['sometimes', 'string', 'max:32'],
        ]);

        $updated = $service->updateBand($school, $model, $band, $validated, $request->user());

        return response()->json(['data' => $this->presentBand($updated)]);
    }

    public function destroyBand(Request $request, School $school, string $gradeScale, string $gradeBand, GradeScaleService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);
        $band = GradeBand::query()->where('grade_scale_id', $model->id)->findOrFail($gradeBand);

        $service->removeBand($school, $model, $band, $request->user());

        return response()->json(null, 204);
    }

    private function uniqueCodeRule(School $school): Unique
    {
        return Rule::unique('grade_scales', 'code')->where('school_id', $school->id);
    }

    /**
     * `schoolId` is deliberately not exposed.
     *
     * @return array<string, mixed>
     */
    private function present(GradeScale $scale): array
    {
        return [
            'id' => $scale->id,
            'code' => $scale->code,
            'name' => $scale->name,
            'status' => $scale->status,
            'bands' => $scale->bands->map(fn (GradeBand $b) => $this->presentBand($b))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBand(GradeBand $band): array
    {
        return [
            'id' => $band->id,
            'minPercentage' => (string) $band->min_percentage,
            'label' => $band->label,
        ];
    }
}
