<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Http\TranslatesFeeSetupErrors;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FEE.1 (ADR 0062 §5): fee head API. Reads need
 * finance.fee_structures.view, writes finance.fee_structures.manage
 * (route middleware, and again in the Application services). No DELETE:
 * a fee head is deactivated through `status`.
 */
class FeeHeadController extends Controller
{
    use NormalizesCodeInput, TranslatesFeeSetupErrors;

    public function index(Request $request, School $school, FeeStructureReadService $reads): JsonResponse
    {
        $validated = $request->validate(['include_inactive' => ['sometimes', 'boolean']]);

        $heads = $reads->listFeeHeads($school, $request->user(), (bool) ($validated['include_inactive'] ?? true));

        return response()->json(['data' => $heads->map(fn (FeeHead $h) => FeeSetupPresenter::feeHead($h))->values()->all()]);
    }

    public function show(Request $request, School $school, string $feeHead, FeeStructureReadService $reads): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::feeHead($reads->getFeeHead($school, $feeHead, $request->user()))]);
    }

    public function store(Request $request, School $school, FeeHeadService $service): JsonResponse
    {
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('fee_heads', $school)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
        ]);

        $head = $this->translatingFeeSetupErrors(fn () => $service->create($school, $validated, $request->user()));

        return response()->json(['data' => FeeSetupPresenter::feeHead($head)], 201);
    }

    public function update(Request $request, School $school, string $feeHead, FeeHeadService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receivable_ledger_account_id' => ['sometimes', 'uuid'],
            'revenue_ledger_account_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(FeeHead::STATUSES)],
        ]);

        $head = $this->translatingFeeSetupErrors(function () use ($school, $feeHead, $validated, $service, $request) {
            $fields = array_intersect_key($validated, array_flip(['name', 'description', 'receivable_ledger_account_id', 'revenue_ledger_account_id']));
            $head = $fields === [] ? null : $service->update($school, $feeHead, $fields, $request->user());

            return match ($validated['status'] ?? null) {
                FeeHead::STATUS_INACTIVE => $service->deactivate($school, $feeHead, $request->user()),
                FeeHead::STATUS_ACTIVE => $service->reactivate($school, $feeHead, $request->user()),
                default => $head ?? $service->update($school, $feeHead, [], $request->user()),
            };
        });

        return response()->json(['data' => FeeSetupPresenter::feeHead($head)]);
    }
}
