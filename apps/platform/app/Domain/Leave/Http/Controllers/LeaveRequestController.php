<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
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
 * HRX.2 (ADR 0065 §23): the Leave request, manager-approval and year-close
 * API.
 *
 * The controller validates and delegates. Every capability, ownership,
 * state and tenancy check is in the services. Mutations are `idempotent`.
 * Approval, cancellation and the year close complete their idempotency
 * record INSIDE the business transaction (rule 33).
 */
class LeaveRequestController extends Controller
{
    private const PORTIONS = ['full', 'first_half', 'second_half'];

    public function index(Request $request, School $school, LeaveRequestReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(LeaveRequest::STATUSES)],
            'employment_record_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(['data' => $reads->list($school, $validated, $request->user())]);
    }

    public function show(Request $request, School $school, string $leaveRequest, LeaveRequestReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);

        return response()->json(['data' => $reads->show($school, $leaveRequest, $request->user())]);
    }

    public function store(Request $request, School $school, LeaveRequestService $service): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'start_portion' => ['required', Rule::in(self::PORTIONS)],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'end_portion' => ['required', Rule::in(self::PORTIONS)],
            'reason_code' => ['sometimes', 'nullable', Rule::in(LeaveRequest::REASONS)],
        ]);
        $created = $service->submitOnBehalf($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['starts_on'],
            $validated['start_portion'], $validated['ends_on'], $validated['end_portion'], $validated['reason_code'] ?? null, $request->user());

        return response()->json(['data' => LeaveRequestReadService::request($created)], 201);
    }

    public function approve(Request $request, School $school, string $leaveRequest, LeaveRequestService $service, IdempotencyGuard $guard): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);

        return $this->completing($request, $guard, 200, fn () => LeaveRequestReadService::request($service->approve($school, $leaveRequest, $request->user())));
    }

    public function reject(Request $request, School $school, string $leaveRequest, LeaveRequestService $service): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::REJECTION_REASONS)]]);

        return response()->json(['data' => LeaveRequestReadService::request($service->reject($school, $leaveRequest, $validated['reason_code'], $request->user()))]);
    }

    public function withdraw(Request $request, School $school, string $leaveRequest, LeaveRequestService $service): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::CLOSING_REASONS)]]);

        return response()->json(['data' => LeaveRequestReadService::request($service->withdraw($school, $leaveRequest, $validated['reason_code'], $request->user()))]);
    }

    public function cancel(Request $request, School $school, string $leaveRequest, LeaveRequestService $service, IdempotencyGuard $guard): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::CLOSING_REASONS)]]);

        return $this->completing($request, $guard, 200, fn () => LeaveRequestReadService::request($service->cancel($school, $leaveRequest, $validated['reason_code'], $request->user())));
    }

    public function managed(Request $request, School $school, LeaveRequestReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->managed($school, $request->user())]);
    }

    public function managedShow(Request $request, School $school, string $leaveRequest, LeaveRequestReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);

        return response()->json(['data' => $reads->managedShow($school, $leaveRequest, $request->user())]);
    }

    public function managedApprove(Request $request, School $school, string $leaveRequest, LeaveRequestService $service, IdempotencyGuard $guard): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);

        return $this->completing($request, $guard, 200, fn () => LeaveRequestReadService::request($service->approveAsManager($school, $leaveRequest, $request->user())));
    }

    public function managedReject(Request $request, School $school, string $leaveRequest, LeaveRequestService $service): JsonResponse
    {
        abort_if(! Str::isUuid($leaveRequest), 404);
        $validated = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::REJECTION_REASONS)]]);

        return response()->json(['data' => LeaveRequestReadService::request($service->rejectAsManager($school, $leaveRequest, $validated['reason_code'], $request->user()))]);
    }

    public function closes(Request $request, School $school, LeaveRequestReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->closes($school, $request->user())]);
    }

    public function showClose(Request $request, School $school, string $leaveYearClose, LeaveRequestReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($leaveYearClose), 404);

        return response()->json(['data' => $reads->showClose($school, $leaveYearClose, $request->user())]);
    }

    public function previewClose(Request $request, School $school, LeaveYearCloseService $closes): JsonResponse
    {
        $validated = $request->validate(['leave_year_id' => ['required', 'uuid']]);

        return response()->json(['data' => $closes->preview($school, $validated['leave_year_id'], $request->user())]);
    }

    public function executeClose(Request $request, School $school, LeaveYearCloseService $closes, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate(['leave_year_id' => ['required', 'uuid']]);

        return $this->completing($request, $guard, 201, fn () => LeaveRequestReadService::close($closes->execute($school, $validated['leave_year_id'], $request->user())));
    }

    /** Runs the command and completes the idempotency record in the same transaction (rule 33). */
    private function completing(Request $request, IdempotencyGuard $guard, int $status, callable $command): JsonResponse
    {
        $record = $request->attributes->get('idempotency_record');
        $body = DB::transaction(function () use ($command, $guard, $record, $status) {
            $body = ['data' => $command()];
            if ($record !== null) {
                $guard->completeWithin($record, $status, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body, $status);
    }
}
