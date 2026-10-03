<?php

namespace App\Http\Controllers\App\StaffAttendance;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceCapabilities;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRX.3 (ADR 0065 §24.15): the session-authenticated Staff Attendance
 * administration pages -- the daily register (single record, bulk save,
 * correction) and one employment's history with its correction evidence.
 *
 * Every page needs `hr.staff_attendance.view`; the forms are offered only to
 * `hr.staff_attendance.manage`, and every command is authorized again by
 * StaffAttendanceService. A domain refusal is a form error (field
 * `attendance`) carrying its stable machine code. No self-service, Teacher
 * or clock page.
 */
class StaffAttendanceAdminController extends Controller
{
    use AuthorizesCapability;

    public function register(Request $request, TenantContext $context, CapabilityResolver $capabilities, StaffAttendanceReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::VIEW, $school);
        $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? $today;

        return Inertia::render('App/StaffAttendance/Register', [
            'register' => $reads->register($school, $date, $context->actor()),
            'today' => $today,
            'reasons' => StaffAttendanceCorrection::REASONS,
            'canManage' => $capabilities->canInSchool($context->actor(), StaffAttendanceCapabilities::MANAGE, $school),
        ]);
    }

    public function history(Request $request, TenantContext $context, CapabilityResolver $capabilities, StaffAttendanceReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::VIEW, $school);
        $today = CarbonImmutable::now(SchoolTimezone::resolve($school));
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $to = $validated['to'] ?? $today->toDateString();
        $from = $validated['from'] ?? CarbonImmutable::createFromFormat('!Y-m-d', $to)->subDays(29)->toDateString();

        try {
            $history = $reads->history($school, $validated['employment_record_id'], $from, $to, $context->actor());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (StaffAttendanceException $e) {
            throw ValidationException::withMessages(['attendance' => "{$e->getMessage()} ({$e->errorCode()})"]);
        }

        return Inertia::render('App/StaffAttendance/History', [
            'history' => $history,
            'corrections' => $reads->corrections($school, array_values(array_filter(array_map(fn (array $d) => $d['record']['id'] ?? null, $history['days']))), $context->actor()),
            'today' => $today->toDateString(),
            'reasons' => StaffAttendanceCorrection::REASONS,
            'canManage' => $capabilities->canInSchool($context->actor(), StaffAttendanceCapabilities::MANAGE, $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, StaffAttendanceService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            ...self::halfRules(''),
        ]);

        return $this->command(fn () => $service->record($school, $validated['employment_record_id'], $validated['date'], $validated['first_half'], $validated['second_half'], $request->user()), 'Attendance recorded.');
    }

    public function storeRegister(Request $request, TenantContext $context, StaffAttendanceService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:'.StaffAttendanceService::MAX_REGISTER_ITEMS],
            'items.*.employment_record_id' => ['required', 'uuid'],
            ...self::halfRules('items.*.'),
        ]);

        return $this->command(fn () => $service->recordRegister($school, $validated['date'], array_values($validated['items']), $request->user()), 'Register saved.');
    }

    public function correct(Request $request, TenantContext $context, StaffAttendanceService $service, string $staffAttendanceRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($staffAttendanceRecord), 404);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            ...self::halfRules(''),
            'reason_code' => ['required', Rule::in(StaffAttendanceCorrection::REASONS)],
        ]);

        return $this->command(fn () => $service->correct($school, $staffAttendanceRecord, (int) $validated['expected_version'], $validated['first_half'], $validated['second_half'], $validated['reason_code'], $request->user()), 'Attendance corrected.');
    }

    /** @return array<string, list<mixed>> */
    private static function halfRules(string $prefix): array
    {
        return [
            "{$prefix}first_half" => ['present', 'nullable', Rule::in(StaffAttendanceRecord::HALF_STATUSES)],
            "{$prefix}second_half" => ['present', 'nullable', Rule::in(StaffAttendanceRecord::HALF_STATUSES)],
        ];
    }

    /** Runs a command and redirects back; a domain refusal becomes a form error, never a leak. */
    private function command(callable $command, string $success): RedirectResponse
    {
        try {
            $command();
        } catch (StaffAttendanceException|LeaveException $e) {
            throw ValidationException::withMessages(['attendance' => "{$e->getMessage()} ({$e->errorCode()})"]);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['attendance' => 'Choose a record of this School.']);
        }

        return back()->with('status', $success);
    }
}
