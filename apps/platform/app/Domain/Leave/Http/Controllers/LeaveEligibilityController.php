<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityOutcome;
use App\Domain\Leave\Http\Requests\LeavePolicyInput;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HR.9 (ADR 0065 §28.4) -- Leave policy eligibility API. Thin: validate ->
 * delegate -> present; every service call authorizes its own capability.
 *
 * - GET  eligibility          `hr.leave.view`: every employment's outcome
 *   for one leave type and date (the assigned / unassigned staff view).
 * - POST eligibility/preview  `hr.leave.configure`: the same for a draft
 *   version; writes nothing (so it is not `idempotent`).
 * - POST eligibility-runs     `hr.leave.manage`: the additive run. It creates
 *   assignments, so it is `idempotent` (rule 29) and completes the stored
 *   replay inside its transaction (rule 33). The replay holds the run's ids
 *   and counts only (rule 36).
 */
class LeaveEligibilityController extends Controller
{
    public function show(Request $request, School $school, LeaveEligibilityService $eligibility): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'date' => ['sometimes', 'date_format:Y-m-d'],
            'outcome' => ['sometimes', Rule::in(array_column(LeaveEligibilityOutcome::options(), 'value'))],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:500']]);

        return response()->json(['data' => $eligibility->applicability($school, $validated['leave_type_id'], $validated['date'] ?? SchoolTimezone::today($school), $request->user(),
            $validated['outcome'] ?? null, (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 200))]);
    }

    public function preview(Request $request, School $school, LeaveEligibilityService $eligibility): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'supersedes_policy_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'annual_allocation_units' => ['required', 'integer', 'min:0', 'max:1000'],
            ...LeavePolicyInput::eligibilityRules(),
        ]);

        return response()->json(['data' => $eligibility->previewDraft(
            $school, $validated['leave_type_id'], $validated['date'] ?? SchoolTimezone::today($school), $validated['supersedes_policy_id'] ?? null,
            $validated['name'], (int) $validated['annual_allocation_units'], LeavePolicyInput::criteria($validated), $request->user(),
        )]);
    }

    public function executeRun(Request $request, School $school, LeaveEligibilityService $eligibility, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $record = $request->attributes->get('idempotency_record');

        $body = DB::transaction(function () use ($school, $validated, $eligibility, $guard, $record, $request) {
            $run = $eligibility->executeRun($school, $validated['leave_type_id'], $validated['as_of'], $request->user());
            $body = ['data' => LeaveReadService::eligibilityRun($run)];
            if ($record !== null) {
                $guard->completeWithin($record, 201, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body, 201);
    }
}
