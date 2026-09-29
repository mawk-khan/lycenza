<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\Exceptions\InvalidFeeAssessmentRunException;
use App\Domain\Fees\Application\FeeAssessmentRunReadService;
use App\Domain\Fees\Application\FeeAssessmentRunService;
use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FEE.2 (ADR 0062 §9): the assessment-run API. Reads need
 * finance.charges.view; create/preview/exclude/execute/resume/cancel need
 * finance.fee_assessments.run; voiding an assessment needs
 * finance.charges.manage -- each on the route AND in the Application
 * service. Nothing here creates a charge directly; preview creates none at
 * all.
 */
class FeeAssessmentRunController extends Controller
{
    public function index(Request $request, School $school, FeeAssessmentRunReadService $reads): JsonResponse
    {
        $filters = $request->validate([
            'fee_structure_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(FeeAssessmentRun::STATUSES)],
        ]);

        return response()->json(['data' => $reads->listRuns($school, $filters, $request->user())
            ->map(fn (FeeAssessmentRun $r) => FeeSetupPresenter::run($r))->values()->all()]);
    }

    public function show(Request $request, School $school, string $run, FeeAssessmentRunReadService $reads): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($reads->getRun($school, $run, $request->user()))]);
    }

    public function items(Request $request, School $school, string $run, FeeAssessmentRunReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'preview_result' => ['sometimes', 'string', Rule::in(FeeAssessmentRunItem::PREVIEW_RESULTS)],
            'execution_status' => ['sometimes', 'string', Rule::in([FeeAssessmentRunItem::EXEC_PENDING, FeeAssessmentRunItem::EXEC_SUCCEEDED, FeeAssessmentRunItem::EXEC_SKIPPED, FeeAssessmentRunItem::EXEC_FAILED])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $reads->listItems($school, $run, array_intersect_key($validated, array_flip(['preview_result', 'execution_status'])), (int) ($validated['page'] ?? 1), $request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (FeeAssessmentRunItem $i) => FeeSetupPresenter::runItem($i))->values()->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, School $school, FeeAssessmentRunService $runs): JsonResponse
    {
        $validated = $request->validate([
            'fee_structure_id' => ['required', 'uuid'],
            'billing_period_key' => ['required', 'string', 'max:32'],
        ]);

        $run = $this->fieldErrors(fn () => $runs->create($school, $validated['fee_structure_id'], $validated['billing_period_key'], $request->user()));

        return response()->json(['data' => FeeSetupPresenter::run($run)], 201);
    }

    public function preview(Request $request, School $school, string $run, FeeAssessmentRunService $runs): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($this->fieldErrors(fn () => $runs->preview($school, $run, $request->user())))]);
    }

    public function exclude(Request $request, School $school, string $run, string $item, FeeAssessmentRunService $runs): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($runs->excludeItem($school, $run, $item, $request->user()))]);
    }

    public function execute(Request $request, School $school, string $run, FeeAssessmentRunService $runs): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($runs->execute($school, $run, $request->user()))], 202);
    }

    public function resume(Request $request, School $school, string $run, FeeAssessmentRunService $runs): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($runs->resume($school, $run, $request->user()))], 202);
    }

    public function cancel(Request $request, School $school, string $run, FeeAssessmentRunService $runs): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::run($runs->cancel($school, $run, $request->user()))]);
    }

    public function void(Request $request, School $school, string $assessment, FeeAssessmentService $assessments): JsonResponse
    {
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return response()->json(['data' => FeeSetupPresenter::assessment($assessments->void($school, $assessment, $request->user(), $validated['reason'] ?? null))]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function fieldErrors(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidFeeAssessmentRunException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }
    }
}
