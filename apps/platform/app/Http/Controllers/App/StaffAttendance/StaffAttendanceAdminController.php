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
use App\Support\Auth\Mfa\FreshMfaRequirement;
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
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $date = $validated['date'] ?? $today;

        $canManage = $capabilities->canInSchool($context->actor(), StaffAttendanceCapabilities::MANAGE, $school);

        return Inertia::render('App/StaffAttendance/Register', [
            // HR.16C: one server page of the whole register (no population cap).
            'register' => $reads->register($school, $date, $context->actor(), (int) ($validated['page'] ?? 1)),
            'today' => $today,
            'reasons' => StaffAttendanceCorrection::REASONS,
            'canManage' => $canManage,
            // HR.R1-C18 (S-6): a locked month takes no record and no ordinary correction, only a request.
            'monthLock' => $reads->monthLock($school, $date, $context->actor()),
            'pendingCorrections' => $reads->pendingLockedCorrections($school, $date, $context->actor()),
            'formKey' => $canManage ? $this->issueFormKey($request) : null,
        ]);
    }

    /**
     * ORG.9C-1 (ADR 0076 §69), HR-003: a month's composed days counted per
     * employment in half-days (present, absent, leave, holiday, off day,
     * unrecorded) -- never late, no-punch or loss of pay. Read-only.
     */
    public function monthly(Request $request, TenantContext $context, StaffAttendanceReadService $reads, StaffAttendanceService $service, CapabilityResolver $capabilities, FreshMfaRequirement $mfa): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::VIEW, $school);
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $month = $validated['month'] ?? substr(SchoolTimezone::today($school), 0, 7);

        try {
            $summary = $reads->monthSummary($school, $month, $context->actor(), (int) ($validated['page'] ?? 1));
        } catch (StaffAttendanceException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        // HR.R1-C18 (S-6): the lock and the month's correction requests; what this actor may decide.
        $requests = $reads->lockedCorrections($school, $month, $context->actor());
        $decidable = $service->decidable($school, array_column(array_filter($requests, fn (array $r) => $r['status'] === 'requested'), 'id'), $context->actor());

        return Inertia::render('App/StaffAttendance/Monthly', [
            'summary' => $summary,
            'currentMonth' => substr(SchoolTimezone::today($school), 0, 7),
            'lock' => $reads->monthLock($school, $month, $context->actor()),
            'canLock' => $capabilities->canInSchool($context->actor(), StaffAttendanceCapabilities::LOCK, $school),
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
            'requests' => array_map(fn (array $r) => [...$r, 'canDecide' => in_array($r['id'], $decidable, true)], $requests),
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

    /**
     * HR.R1-C18 (WO-6; S6-1 to S6-3): locks a School-local calendar month for good. The capability,
     * the explicit confirmation, then the fresh MFA step-up OUTSIDE the business transaction (rule 95);
     * the service re-reads the authority under its lock.
     */
    public function lockMonth(Request $request, TenantContext $context, StaffAttendanceService $service, FreshMfaRequirement $mfa, string $month): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::LOCK, $school);
        abort_if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1, 404);
        $request->validate(['confirm' => ['accepted']]);
        $proof = $mfa->requireForAction($request, $context->actor());

        return $this->command(fn () => $service->lockMonth($school, $month, $context->actor(), $proof), "{$month} is locked.");
    }

    /** HR.R1-C18: asks for a change to a record of a locked month. It changes nothing until someone else approves it. */
    public function requestLockedCorrection(Request $request, TenantContext $context, StaffAttendanceService $service, string $staffAttendanceRecord): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::MANAGE, $school);
        abort_if(! Str::isUuid($staffAttendanceRecord), 404);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            ...self::halfRules(''),
            'reason_code' => ['required', Rule::in(StaffAttendanceCorrection::REASONS)],
            'form_key' => ['required', 'uuid'],
        ]);
        $this->requireIssuedFormKey($request, $validated['form_key']);

        return $this->command(fn () => $service->requestLockedCorrection($school, $staffAttendanceRecord, (int) $validated['expected_version'], $validated['first_half'], $validated['second_half'], $validated['reason_code'], $validated['form_key'], $context->actor()), 'Correction requested. Someone else with the lock key decides it.');
    }

    public function approveLockedCorrection(Request $request, TenantContext $context, StaffAttendanceService $service, FreshMfaRequirement $mfa, string $lockedCorrection): RedirectResponse
    {
        return $this->decide($request, $context, $mfa, $lockedCorrection, fn ($school, $proof) => $service->approveLockedCorrection($school, $lockedCorrection, $context->actor(), $proof), 'Correction approved and applied. The month stays locked.');
    }

    public function refuseLockedCorrection(Request $request, TenantContext $context, StaffAttendanceService $service, FreshMfaRequirement $mfa, string $lockedCorrection): RedirectResponse
    {
        return $this->decide($request, $context, $mfa, $lockedCorrection, fn ($school, $proof) => $service->refuseLockedCorrection($school, $lockedCorrection, $context->actor(), $proof), 'Correction refused.');
    }

    private function decide(Request $request, TenantContext $context, FreshMfaRequirement $mfa, string $lockedCorrection, callable $decision, string $success): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::LOCK, $school);
        abort_if(! Str::isUuid($lockedCorrection), 404);
        $request->validate(['confirm' => ['accepted']]);
        $proof = $mfa->requireForAction($request, $context->actor());

        return $this->command(fn () => $decision($school, $proof), $success);
    }

    /** A server-issued key per correction form, so a retry never files a second request (as inventory forms do). */
    private function issueFormKey(Request $request): string
    {
        $key = (string) Str::uuid();
        $keys = (array) $request->session()->get('staff_attendance.form_keys', []);
        $request->session()->put('staff_attendance.form_keys', array_slice([...$keys, $key], -20));

        return $key;
    }

    private function requireIssuedFormKey(Request $request, string $key): void
    {
        if (! in_array($key, (array) $request->session()->get('staff_attendance.form_keys', []), true)) {
            throw ValidationException::withMessages(['attendance' => 'This form has expired. Reload the page and try again.']);
        }
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
