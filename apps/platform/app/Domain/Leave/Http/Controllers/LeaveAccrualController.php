<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\Accrual\LeaveAccrualOutcome;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\Accrual\LeaveStatementReader;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HR.10 (ADR 0065 §29) -- the monthly accrual API. Thin: validate ->
 * delegate -> present; every service call authorizes its own capability.
 *
 * - GET  accruals/preview        `hr.leave.manage`: the month's evaluation, read-only, keyset-paged, with complete counts.
 * - POST accrual-runs            `hr.leave.manage`: open or resume the month's run and process up to `max_batches` batches.
 *   Idempotent BY CONSTRUCTION (one open run per month, one accrual per account and month, resumable cursor), so a retry
 *   continues the same run; it is deliberately not behind the generic `idempotent` middleware, whose stored replay would
 *   return a stale run state (rule 29 reviewed).
 * - GET  accrual-runs, accrual-runs/{run}, accrual-grid   `hr.leave.view`.
 */
class LeaveAccrualController extends Controller
{
    public function preview(Request $request, School $school, LeaveAccrualService $accruals): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'period_start' => ['required', 'date_format:Y-m-d'],
            'after' => ['sometimes', 'uuid'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $accruals->preview($school, $validated['leave_type_id'], $validated['leave_year_id'], $validated['period_start'], $request->user(),
            $validated['after'] ?? null, (int) ($validated['per_page'] ?? 50))]);
    }

    public function execute(Request $request, School $school, LeaveAccrualService $accruals): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'period_start' => ['required', 'date_format:Y-m-d'],
            'max_batches' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $run = $accruals->execute($school, $validated['leave_type_id'], $validated['leave_year_id'], $validated['period_start'], $request->user(), (int) ($validated['max_batches'] ?? 40));

        return response()->json(['data' => $accruals->show($school, $run->id, $request->user(), perPage: 1)], 201);
    }

    public function index(Request $request, School $school, LeaveAccrualService $accruals): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);

        return response()->json(['data' => $accruals->runs($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user())]);
    }

    public function show(Request $request, School $school, string $leaveAccrualRun, LeaveAccrualService $accruals): JsonResponse
    {
        abort_if(! Str::isUuid($leaveAccrualRun), 404);
        $validated = $request->validate([
            'outcome' => ['sometimes', Rule::in(array_column(LeaveAccrualOutcome::options(), 'value'))],
            'after' => ['sometimes', 'uuid'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $accruals->show($school, $leaveAccrualRun, $request->user(), $validated['outcome'] ?? null, $validated['after'] ?? null, (int) ($validated['per_page'] ?? 50))]);
    }

    public function grid(Request $request, School $school, LeaveStatementReader $statements): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'],
            'after' => ['sometimes', 'uuid'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $statements->grid($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user(), $validated['after'] ?? null, (int) ($validated['per_page'] ?? 50))]);
    }
}
