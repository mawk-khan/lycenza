<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeDetail;
use App\Domain\Fees\Application\ChargeQuery;
use App\Domain\Fees\Application\ChargeReadService;
use App\Domain\Fees\Application\ChargeResult;
use App\Domain\Fees\Application\ChargeSummary;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0G.6: thin HTTP transport over `ChargeReadService` (reads) and
 * `ChargeAdministrationService` (assess/cancel) -- never `ChargeService`
 * (the trusted core) directly, and never a raw `Charge` Eloquent query.
 * `{charge}` is always a plain route-parameter string, never
 * Eloquent-bound -- cross-School privacy is entirely
 * `ChargeReadService`/`ChargeAdministrationService`'s job (uniform
 * `ChargeNotFoundException`).
 *
 * No `edit`/`update`/`delete` action exists here at all -- a recognized
 * Charge's amount/Student/AcademicYear/account mapping remain
 * structurally immutable (0G.4); the only post-assessment transition is
 * `cancel()`.
 *
 * Neither `store()` (assess) nor `cancel()` carries the `idempotent`
 * middleware -- 0G.4 explicitly deferred generic Charge-assessment
 * idempotency, and Charge cancellation has its own structural
 * at-most-once semantics (`ChargeAlreadyCancelledException`/
 * `ChargeHasPaymentAllocationsException`, both 409), never a generic
 * HTTP replay.
 */
class ChargeController extends Controller
{
    public function index(Request $request, School $school, ChargeReadService $service): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['sometimes', 'uuid'],
            'academic_year_id' => ['sometimes', 'uuid'],
            'include_cancelled' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new ChargeQuery(
            studentId: $validated['student_id'] ?? null,
            academicYearId: $validated['academic_year_id'] ?? null,
            includeCancelled: (bool) ($validated['include_cancelled'] ?? false),
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? ChargeQuery::DEFAULT_PER_PAGE),
        );

        $page = $service->listCharges($school, $query, $request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn (ChargeSummary $c) => $this->presentSummary($c))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, School $school, string $charge, ChargeReadService $service): JsonResponse
    {
        $detail = $service->getChargeDetail($school, $charge, $request->user());

        return response()->json(['data' => $this->presentDetail($detail)]);
    }

    public function store(Request $request, School $school, ChargeAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $result = $service->assess($school, new AssessChargeData(
            studentId: $validated['student_id'],
            academicYearId: $validated['academic_year_id'],
            description: $validated['description'],
            amount: $this->parseAmount($validated['amount'], $validated['currency']),
            receivableLedgerAccountId: $validated['receivable_ledger_account_id'],
            revenueLedgerAccountId: $validated['revenue_ledger_account_id'],
            dueDate: $validated['due_date'] ?? null,
        ), $request->user());

        return response()->json(['data' => $this->presentResult($result)], 201);
    }

    public function cancel(Request $request, School $school, string $charge, ChargeAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $service->cancel($school, $charge, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $this->presentResult($result)]);
    }

    private function parseAmount(mixed $amount, string $currency): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => ['The amount must be a positive decimal string with at most 2 decimal places (e.g. "1000.00").'],
            ]);
        }

        try {
            return Money::of($amount, $currency);
        } catch (InvalidMoneyException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResult(ChargeResult $result): array
    {
        return [
            'id' => $result->chargeId,
            'studentId' => $result->studentId,
            'academicYearId' => $result->academicYearId,
            'amount' => $result->amount,
            'currency' => $result->currency,
            'journalEntryId' => $result->journalEntryId,
            'cancelledAt' => $result->cancelledAt?->toIso8601String(),
            'cancellationJournalEntryId' => $result->cancellationJournalEntryId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(ChargeSummary $charge): array
    {
        return [
            'id' => $charge->chargeId,
            'studentId' => $charge->studentId,
            'academicYearId' => $charge->academicYearId,
            'description' => $charge->description,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'dueDate' => $charge->dueDate?->toDateString(),
            'cancelledAt' => $charge->cancelledAt?->toIso8601String(),
            'createdAt' => $charge->createdAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(ChargeDetail $charge): array
    {
        return [
            'id' => $charge->chargeId,
            'studentId' => $charge->studentId,
            'academicYearId' => $charge->academicYearId,
            'description' => $charge->description,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'dueDate' => $charge->dueDate?->toDateString(),
            'receivableLedgerAccountId' => $charge->receivableLedgerAccountId,
            'revenueLedgerAccountId' => $charge->revenueLedgerAccountId,
            'journalEntryId' => $charge->journalEntryId,
            'cancelledAt' => $charge->cancelledAt?->toIso8601String(),
            'cancellationJournalEntryId' => $charge->cancellationJournalEntryId,
            'createdAt' => $charge->createdAt->toIso8601String(),
        ];
    }
}
