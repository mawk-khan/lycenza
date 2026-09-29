<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Http\TranslatesFeeSetupErrors;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FEE.1 (ADR 0062 §7): fee structure API -- structures, their lines and the
 * instalment schedule, plus the lifecycle actions activate / retire /
 * successor. Reads need finance.fee_structures.view, writes
 * finance.fee_structures.manage (route middleware and Application services).
 *
 * The only DELETE removes a DRAFT line (the database refuses it once the
 * structure leaves draft). Structures themselves are never deleted.
 */
class FeeStructureController extends Controller
{
    use NormalizesCodeInput, TranslatesFeeSetupErrors;

    public function index(Request $request, School $school, FeeStructureReadService $reads): JsonResponse
    {
        $filters = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(FeeStructure::STATUSES)],
        ]);

        $structures = $reads->listStructures($school, $filters, $request->user());

        return response()->json(['data' => $structures->map(fn (FeeStructure $s) => FeeSetupPresenter::structure($s))->values()->all()]);
    }

    public function show(Request $request, School $school, string $feeStructure, FeeStructureReadService $reads): JsonResponse
    {
        return $this->detail($school, $feeStructure, $request->user(), $reads);
    }

    public function store(Request $request, School $school, FeeStructureService $service): JsonResponse
    {
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'grade_level_id' => ['required', 'uuid'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('fee_structures', $school, [
                'academic_year_id' => (string) $request->input('academic_year_id'),
            ])],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $structure = $this->translatingFeeSetupErrors(fn () => $service->createDraft($school, $validated, $request->user()));

        return $this->written($school, $structure->id, 201);
    }

    public function update(Request $request, School $school, string $feeStructure, FeeStructureService $service): JsonResponse
    {
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $this->translatingFeeSetupErrors(fn () => $service->updateDraft($school, $feeStructure, $validated, $request->user()));

        return $this->written($school, $feeStructure);
    }

    public function activate(Request $request, School $school, string $feeStructure, FeeStructureService $service): JsonResponse
    {
        $service->activate($school, $feeStructure, $request->user());

        return $this->written($school, $feeStructure);
    }

    public function retire(Request $request, School $school, string $feeStructure, FeeStructureService $service): JsonResponse
    {
        $service->retire($school, $feeStructure, $request->user());

        return $this->written($school, $feeStructure);
    }

    public function successor(Request $request, School $school, string $feeStructure, FeeStructureService $service): JsonResponse
    {
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $successor = $this->translatingFeeSetupErrors(
            fn () => $service->createSuccessor($school, $feeStructure, $validated, $request->user()),
        );

        return $this->written($school, $successor->id, 201);
    }

    public function storeLine(Request $request, School $school, string $feeStructure, FeeStructureService $service): JsonResponse
    {
        $validated = $request->validate([
            'fee_head_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'],
            'is_optional' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', 'string', Rule::in(FeeStructureLine::FREQUENCIES)],
        ]);

        $this->translatingFeeSetupErrors(fn () => $service->addLine($school, $feeStructure, $validated, $request->user()));

        return $this->written($school, $feeStructure, 201);
    }

    public function updateLine(Request $request, School $school, string $feeStructure, string $line, FeeStructureService $service): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'string'],
            'is_optional' => ['sometimes', 'boolean'],
        ]);

        $this->translatingFeeSetupErrors(fn () => $service->updateLine($school, $feeStructure, $line, $validated, $request->user()));

        return $this->written($school, $feeStructure);
    }

    public function destroyLine(Request $request, School $school, string $feeStructure, string $line, FeeStructureService $service): JsonResponse
    {
        $service->removeLine($school, $feeStructure, $line, $request->user());

        return response()->json(null, 204);
    }

    public function replaceInstallments(Request $request, School $school, string $feeStructure, string $line, FeeStructureService $service): JsonResponse
    {
        $validated = $request->validate([
            'installments' => ['required', 'array', 'min:1', 'max:24'],
            'installments.*.label' => ['required', 'string', 'max:64'],
            'installments.*.billing_period_key' => ['required', 'string', 'max:32'],
            'installments.*.period_starts_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.period_ends_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.academic_term_id' => ['sometimes', 'nullable', 'uuid'],
            'installments.*.amount' => ['required', 'string'],
        ]);

        $this->translatingFeeSetupErrors(
            fn () => $service->replaceInstallments($school, $feeStructure, $line, $validated['installments'], $request->user()),
        );

        return $this->written($school, $feeStructure);
    }

    public function generateInstallments(Request $request, School $school, string $feeStructure, string $line, FeeStructureService $service): JsonResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', Rule::in(FeeStructureLine::GENERATED_FREQUENCIES)],
        ]);

        $this->translatingFeeSetupErrors(
            fn () => $service->generateInstallments($school, $feeStructure, $line, $validated['frequency'], $request->user()),
        );

        return $this->written($school, $feeStructure);
    }

    /**
     * The response of an already-authorized write: the structure as it now
     * stands. Loaded without a second (view) capability check -- a holder of
     * finance.fee_structures.manage sees exactly what they just wrote.
     */
    private function written(School $school, string $feeStructureId, int $status = 200): JsonResponse
    {
        $structure = app(TenantContext::class)->withSchool($school, fn () => FeeStructure::query()
            ->where('school_id', $school->id)
            ->with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.installments'])
            ->findOrFail($feeStructureId));

        return response()->json(['data' => FeeSetupPresenter::structure($structure, withLines: true)], $status);
    }

    private function detail(School $school, string $feeStructureId, User $actor, FeeStructureReadService $reads, int $status = 200): JsonResponse
    {
        $structure = $reads->getStructure($school, $feeStructureId, $actor);

        return response()->json(['data' => FeeSetupPresenter::structure($structure, withLines: true)], $status);
    }
}
