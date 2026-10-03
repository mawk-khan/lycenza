<?php

namespace App\Domain\StaffAttendance\Http\Controllers;

use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Idempotency\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HRX.3 (ADR 0065 §24): the Staff Attendance API -- the daily register, an
 * employment's history, one record with its corrections, single recording,
 * the bulk daily register and the correction.
 *
 * The controller validates and delegates; capability, tenancy, calendar,
 * leave and version checks are in the services. A mutation answers from the
 * record it wrote (StaffAttendanceReadService::record()), so it needs only
 * `hr.staff_attendance.manage`, never `.view` as well (§24.11). Every
 * mutation completes its idempotency record INSIDE the business transaction
 * (rule 33).
 */
class StaffAttendanceController extends Controller
{
    public function register(Request $request, School $school, StaffAttendanceReadService $reads): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $reads->register($school, $validated['date'], $request->user())]);
    }

    public function history(Request $request, School $school, StaffAttendanceReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $reads->history($school, $validated['employment_record_id'], $validated['from'], $validated['to'], $request->user())]);
    }

    public function show(Request $request, School $school, string $staffAttendanceRecord, StaffAttendanceReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($staffAttendanceRecord), 404);

        return response()->json(['data' => $reads->show($school, $staffAttendanceRecord, $request->user())]);
    }

    public function store(Request $request, School $school, StaffAttendanceService $service, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            ...self::halfRules(''),
        ]);

        return $this->completing($request, $guard, 201, fn () => StaffAttendanceReadService::record($service->record(
            $school, $validated['employment_record_id'], $validated['date'], $validated['first_half'], $validated['second_half'], $request->user(),
        )));
    }

    public function storeRegister(Request $request, School $school, StaffAttendanceService $service, IdempotencyGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:'.StaffAttendanceService::MAX_REGISTER_ITEMS],
            'items.*.employment_record_id' => ['required', 'uuid'],
            ...self::halfRules('items.*.'),
        ]);

        return $this->completing($request, $guard, 201, fn () => array_map(
            fn (StaffAttendanceRecord $r) => StaffAttendanceReadService::record($r),
            $service->recordRegister($school, $validated['date'], array_values($validated['items']), $request->user()),
        ));
    }

    public function correct(Request $request, School $school, string $staffAttendanceRecord, StaffAttendanceService $service, IdempotencyGuard $guard): JsonResponse
    {
        abort_if(! Str::isUuid($staffAttendanceRecord), 404);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            ...self::halfRules(''),
            'reason_code' => ['required', Rule::in(StaffAttendanceCorrection::REASONS)],
        ]);

        return $this->completing($request, $guard, 200, fn () => StaffAttendanceReadService::record($service->correct(
            $school, $staffAttendanceRecord, (int) $validated['expected_version'], $validated['first_half'], $validated['second_half'], $validated['reason_code'], $request->user(),
        )));
    }

    /** @return array<string, list<mixed>> both halves must be sent: `present`, `absent` or null (no evidence) */
    private static function halfRules(string $prefix): array
    {
        return [
            "{$prefix}first_half" => ['present', 'nullable', Rule::in(StaffAttendanceRecord::HALF_STATUSES)],
            "{$prefix}second_half" => ['present', 'nullable', Rule::in(StaffAttendanceRecord::HALF_STATUSES)],
        ];
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
