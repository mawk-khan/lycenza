<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Idempotency\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HRX.1 (ADR 0065 §4.2, §4.5) -- Leave policy assignments, allocations,
 * annual allocation runs, adjustments, ledger and derived balances. Thin.
 *
 * Allocation, run execution and adjustment change an Employee's entitlement,
 * so they are `idempotent` (rule 29) AND complete the idempotency record
 * INSIDE the same transaction as the ledger write (rule 33,
 * IdempotencyGuard::completeWithin): the ledger entry, its audit and the
 * stored replay commit or roll back together. Authorization runs as route
 * middleware before the idempotency guard (rule 32) and again in the
 * service. The stored replay holds ids and units only (rule 36).
 */
class LeaveLedgerController extends Controller
{
    public function assignments(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid']]);

        return response()->json(['data' => $reads->assignments($school, $validated['employment_record_id'], $request->user())]);
    }

    public function storeAssignment(Request $request, School $school, LeavePolicyAssignmentService $service): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'leave_policy_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $assignment = $service->assign($school, $validated['employment_record_id'], $validated['leave_policy_id'], $validated['effective_from'], $validated['effective_to'] ?? null, $request->user());

        return response()->json(['data' => LeaveReadService::assignment($assignment)], 201);
    }

    public function endAssignment(Request $request, School $school, string $leavePolicyAssignment, LeavePolicyAssignmentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($leavePolicyAssignment), 404);
        $validated = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => LeaveReadService::assignment($service->end($school, $leavePolicyAssignment, $validated['effective_to'], $request->user()))]);
    }

    public function allocate(Request $request, School $school, LeaveLedgerService $ledger, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'leave_year_id' => ['required', 'uuid'],
            'units' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $record = $request->attributes->get('idempotency_record');

        $body = DB::transaction(function () use ($school, $validated, $ledger, $guard, $record, $request) {
            $entry = $ledger->allocate($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], (int) $validated['units'], $request->user());
            $body = ['data' => LeaveReadService::entry($entry)];
            if ($record !== null) {
                $guard->completeWithin($record, 201, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body, 201);
    }

    public function previewRun(Request $request, School $school, LeaveLedgerService $ledger): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);
        $candidates = $ledger->runCandidates($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user());

        return response()->json(['data' => [
            'candidateCount' => count($candidates),
            'candidates' => array_map(fn (string $id, array $c) => ['employmentRecordId' => $id, 'units' => $c['units']], array_keys($candidates), array_values($candidates)),
        ]]);
    }

    public function executeRun(Request $request, School $school, LeaveLedgerService $ledger, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);
        $record = $request->attributes->get('idempotency_record');

        $body = DB::transaction(function () use ($school, $validated, $ledger, $guard, $record, $request) {
            $run = $ledger->executeRun($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user());
            $body = ['data' => LeaveReadService::runSummary($run)];
            if ($record !== null) {
                $guard->completeWithin($record, 201, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body, 201);
    }

    public function adjust(Request $request, School $school, LeaveLedgerService $ledger, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'leave_year_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'units' => ['required', 'integer', 'min:1', 'max:1000'],
            'reason' => ['required', Rule::in(LeaveLedgerEntry::ADJUSTMENT_REASONS)],
        ]);
        $record = $request->attributes->get('idempotency_record');

        $body = DB::transaction(function () use ($school, $validated, $ledger, $guard, $record, $request) {
            $entry = $ledger->adjust($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], $validated['direction'], (int) $validated['units'], $validated['reason'], $request->user());
            $body = ['data' => LeaveReadService::entry($entry)];
            if ($record !== null) {
                $guard->completeWithin($record, 201, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body, 201);
    }

    public function ledger(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);

        return response()->json(['data' => $reads->ledger($school, $validated['employment_record_id'], $validated['leave_year_id'], $request->user())]);
    }

    public function balances(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);

        return response()->json(['data' => $reads->balances($school, $validated['employment_record_id'], $validated['leave_year_id'], $request->user())]);
    }
}
