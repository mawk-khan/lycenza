<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\Exceptions\InvalidFeeConcessionException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Application\FeeConcessionReadService;
use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Fees\Application\RequestFeeConcessionData;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FEE.3 (ADR 0062 §14, §19): the concessions API. Reads need
 * finance.fee_concessions.view; request/withdraw .request;
 * approve/reject/revoke and adjustment cancellation .approve -- each on the
 * route AND in the Application service. The concession account setting
 * needs finance.fee_structures.view/.manage. There is no DELETE and no
 * free-text note. A request carries a client-generated `idempotency_key`
 * (UUID): the same requester re-sending identical content gets the
 * original back (200), anything else is a 409.
 */
class FeeConcessionController extends Controller
{
    public function index(Request $request, School $school, FeeConcessionReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(FeeConcession::STATUSES)],
            'scope' => ['sometimes', 'string', Rule::in([FeeConcession::SCOPE_TARGETED, FeeConcession::SCOPE_STANDING])],
            'student_id' => ['sometimes', 'uuid'],
            'charge_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $reads->list($school, array_intersect_key($validated, array_flip(['status', 'scope', 'student_id', 'charge_id'])), (int) ($validated['page'] ?? 1), $request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (FeeConcession $c) => FeeSetupPresenter::concession($c))->values()->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, School $school, string $concession, FeeConcessionReadService $reads): JsonResponse
    {
        $result = $reads->get($school, $concession, $request->user());

        return response()->json(['data' => [
            ...FeeSetupPresenter::concession($result['concession']),
            'adjustments' => $result['adjustments']->map(fn (FeeAdjustment $a) => FeeSetupPresenter::adjustment($a))->values()->all(),
        ]]);
    }

    public function store(Request $request, School $school, FeeConcessionService $concessions): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'scope' => ['required', 'string', Rule::in([FeeConcession::SCOPE_TARGETED, FeeConcession::SCOPE_STANDING])],
            'category' => ['required', 'string', Rule::in(FeeConcession::CATEGORIES)],
            'kind' => ['required', 'string', Rule::in([FeeConcession::KIND_FIXED, FeeConcession::KIND_PERCENTAGE])],
            'fixed_amount' => ['nullable', 'string', 'max:20'],
            'percentage' => ['nullable', 'string', 'max:8'],
            'charge_id' => ['nullable', 'uuid'],
            'student_id' => ['nullable', 'uuid'],
            'academic_year_id' => ['nullable', 'uuid'],
            'fee_head_id' => ['nullable', 'uuid'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $concessions->request($school, self::requestData($validated), $request->user());
        } catch (InvalidFeeConcessionException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }

        return response()->json(['data' => FeeSetupPresenter::concession($result['concession'])], $result['replayed'] ? 200 : 201);
    }

    public function withdraw(Request $request, School $school, string $concession, FeeConcessionService $concessions): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::concession($concessions->withdraw($school, $concession, $request->user()))]);
    }

    public function approve(Request $request, School $school, string $concession, FeeConcessionService $concessions): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::concession($concessions->approve($school, $concession, $request->user()))]);
    }

    public function reject(Request $request, School $school, string $concession, FeeConcessionService $concessions): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::concession($concessions->reject($school, $concession, $request->user()))]);
    }

    public function revoke(Request $request, School $school, string $concession, FeeConcessionService $concessions): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::concession($concessions->revoke($school, $concession, $request->user()))]);
    }

    public function adjustments(Request $request, School $school, FeeConcessionReadService $reads): JsonResponse
    {
        $filters = $request->validate([
            'charge_id' => ['sometimes', 'uuid'],
            'fee_concession_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(['data' => $reads->listAdjustments($school, $filters, $request->user())
            ->map(fn (FeeAdjustment $a) => FeeSetupPresenter::adjustment($a))->values()->all()]);
    }

    public function cancelAdjustment(Request $request, School $school, string $adjustment, FeeConcessionService $concessions): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => FeeSetupPresenter::adjustment($concessions->cancelAdjustment($school, $adjustment, $request->user(), $validated['reason'] ?? null))]);
    }

    public function settings(Request $request, School $school, FeeSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => ['concessionLedgerAccountId' => $settings->concessionAccountId($school, $request->user())]]);
    }

    public function updateSettings(Request $request, School $school, FeeSettingsService $settings): JsonResponse
    {
        $validated = $request->validate(['concession_ledger_account_id' => ['required', 'uuid']]);

        try {
            $row = $settings->setConcessionAccount($school, $validated['concession_ledger_account_id'], $request->user());
        } catch (InvalidFeeSettingsException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }

        return response()->json(['data' => ['concessionLedgerAccountId' => $row->concession_ledger_account_id]]);
    }

    /** @param array<string, mixed> $v */
    public static function requestData(array $v): RequestFeeConcessionData
    {
        return new RequestFeeConcessionData(
            idempotencyKey: (string) $v['idempotency_key'],
            scope: (string) $v['scope'],
            category: (string) $v['category'],
            kind: (string) $v['kind'],
            fixedAmount: isset($v['fixed_amount']) ? trim((string) $v['fixed_amount']) : null,
            percentage: isset($v['percentage']) ? trim((string) $v['percentage']) : null,
            chargeId: $v['charge_id'] ?? null,
            studentId: $v['student_id'] ?? null,
            academicYearId: $v['academic_year_id'] ?? null,
            feeHeadId: $v['fee_head_id'] ?? null,
            validFrom: $v['valid_from'] ?? null,
            validTo: $v['valid_to'] ?? null,
        );
    }
}
