<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Idempotency\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HRX.4 (ADR 0065 §25.4): the acting Employee's OWN leave -- overview
 * (types, current balances), own requests, submit, withdraw, cancel before
 * start. `hr.leave.self` plus ActingEmployee ownership, both checked in the
 * services; the client never names an Employee, EmploymentRecord or School.
 * Anything not owned is one identical private 404. Cancellation completes its
 * idempotency record inside the business transaction (rule 33).
 */
class MyLeaveController extends Controller
{
    private const PORTIONS = ['full', 'first_half', 'second_half'];

    public function overview(Request $request, School $school, LeaveRequestReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->ownOverview($school, $request->user())]);
    }

    public function index(Request $request, School $school, LeaveRequestReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->own($school, $request->user())]);
    }

    public function show(Request $request, School $school, string $leaveRequest, LeaveRequestReadService $reads): JsonResponse
    {
        self::requireUuid($leaveRequest);

        return response()->json(['data' => $reads->ownShow($school, $leaveRequest, $request->user())]);
    }

    public function store(Request $request, School $school, LeaveRequestService $service): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'start_portion' => ['required', Rule::in(self::PORTIONS)],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'end_portion' => ['required', Rule::in(self::PORTIONS)],
            'reason_code' => ['sometimes', 'nullable', Rule::in(LeaveRequest::REASONS)],
            // ADR 0065 §25.1: identity is ActingEmployee's, never the client's -- naming one is refused, not ignored.
            'employee_id' => ['prohibited'], 'employment_record_id' => ['prohibited'], 'school_id' => ['prohibited'], 'requester_user_id' => ['prohibited'], 'manager_id' => ['prohibited'],
        ]);
        $created = $service->submitOwn($school, $validated['leave_type_id'], $validated['starts_on'], $validated['start_portion'],
            $validated['ends_on'], $validated['end_portion'], $validated['reason_code'] ?? null, $request->user());

        return response()->json(['data' => LeaveRequestReadService::request($created)], 201);
    }

    public function withdraw(Request $request, School $school, string $leaveRequest, LeaveRequestService $service): JsonResponse
    {
        self::requireUuid($leaveRequest);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::CLOSING_REASONS)]]);

        return response()->json(['data' => LeaveRequestReadService::request($service->withdrawOwn($school, $leaveRequest, $validated['reason_code'], $request->user()))]);
    }

    public function cancel(Request $request, School $school, string $leaveRequest, LeaveRequestService $service, IdempotencyGuard $guard): JsonResponse
    {
        self::requireUuid($leaveRequest);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::CLOSING_REASONS)]]);
        $record = $request->attributes->get('idempotency_record');
        $body = DB::transaction(function () use ($service, $school, $leaveRequest, $validated, $request, $guard, $record) {
            $body = ['data' => LeaveRequestReadService::request($service->cancelOwn($school, $leaveRequest, $validated['reason_code'], $request->user()))];
            if ($record !== null) {
                $guard->completeWithin($record, 200, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body);
    }

    /** A malformed id answers exactly like an unknown or unowned one. */
    private static function requireUuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw LeaveRequestService::privateNotFound();
        }
    }
}
