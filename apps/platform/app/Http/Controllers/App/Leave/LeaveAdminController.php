<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
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
 * HRX.2 (ADR 0065 §23.14): the session-authenticated Leave ADMINISTRATION
 * pages:
 * - configuration (settings, years, types, policies, staff calendar);
 * - entitlements (policy assignments, allocations, adjustments, the annual
 *   run, balances, ledger);
 * - requests (submit on behalf, decide, withdraw, cancel, evidence);
 * - the year close.
 *
 * Every page needs `hr.leave.view`. Each form is offered only to the
 * capability that owns it (`configure` / `manage`), and every command is
 * authorized again by its service. No self-service screen, no
 * Teacher-specific screen. The manager surface is LeaveApprovalsController.
 */
class LeaveAdminController extends Controller
{
    use AuthorizesCapability, LeavePageSupport;

    private const PORTIONS = ['full', 'first_half', 'second_half'];

    public function configuration(TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $today = CarbonImmutable::now()->toDateString();

        return Inertia::render('App/Leave/Configuration', [
            'settings' => $reads->settings($school, $actor),
            'years' => $reads->leaveYears($school, $actor),
            'types' => $reads->types($school, $actor),
            'policies' => $reads->policies($school, null, $actor),
            'calendar' => $reads->calendar($school, CarbonImmutable::parse($today)->subYear()->toDateString(), CarbonImmutable::parse($today)->addYears(2)->toDateString(), $actor),
            'portions' => self::PORTIONS,
            'canConfigure' => $capabilities->canInSchool($actor, LeaveCapabilities::CONFIGURE, $school),
        ]);
    }

    public function updateSettings(Request $request, TenantContext $context, LeaveYearService $years): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_year_start_month' => ['required', 'integer', 'between:1,12']]);

        return $this->command(fn () => $years->setStartMonth($school, (int) $validated['leave_year_start_month'], $request->user()), 'Leave-year start month saved.');
    }

    public function scheduleStartChange(Request $request, TenantContext $context, LeaveYearService $years): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['start_month' => ['required', 'integer', 'between:1,12'], 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return $this->command(fn () => $years->scheduleStartChange($school, (int) $validated['start_month'], $validated['effective_from'], $request->user()), 'Start-month change scheduled.');
    }

    public function openYear(Request $request, TenantContext $context, LeaveYearService $years): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['on' => ['required', 'date_format:Y-m-d']]);

        return $this->command(fn () => $years->open($school, $validated['on'], $request->user()), 'Leave year opened.');
    }

    public function storeType(Request $request, TenantContext $context, LeaveTypeService $types): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:120'],
            'is_paid' => ['required', 'boolean'], 'tracks_balance' => ['required', 'boolean'], 'allows_half_day' => ['required', 'boolean'],
        ]);

        return $this->command(fn () => $types->create($school, Str::upper(trim($validated['code'])), $validated['name'], (bool) $validated['is_paid'], (bool) $validated['tracks_balance'], (bool) $validated['allows_half_day'], $request->user()), 'Leave type created.');
    }

    public function setTypeStatus(Request $request, TenantContext $context, LeaveTypeService $types, string $leaveType): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return $this->command(fn () => $types->setStatus($school, $leaveType, $validated['status'], $request->user()), 'Leave type updated.');
    }

    public function storePolicy(Request $request, TenantContext $context, LeavePolicyService $policies): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'],
            'annual_allocation_units' => ['required', 'integer', 'min:0', 'max:1000'], 'carry_forward_allowed' => ['required', 'boolean'],
            'carry_forward_cap_units' => ['nullable', 'integer', 'min:1', 'max:1000'], 'carry_forward_expiry_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'supersedes_policy_id' => ['nullable', 'uuid'],
        ]);
        $terms = ['name' => $validated['name'], 'annual_allocation_units' => (int) $validated['annual_allocation_units'], 'carry_forward_allowed' => (bool) $validated['carry_forward_allowed'],
            'carry_forward_cap_units' => $validated['carry_forward_cap_units'] ?? null, 'carry_forward_expiry_days' => $validated['carry_forward_expiry_days'] ?? null];

        return $this->command(fn () => $policies->create($school, $validated['leave_type_id'], $terms, $validated['supersedes_policy_id'] ?? null, $request->user()), 'Leave policy created.');
    }

    public function retirePolicy(Request $request, TenantContext $context, LeavePolicyService $policies, string $leavePolicy): RedirectResponse
    {
        $school = $context->requireSchool();

        return $this->command(fn () => $policies->retire($school, $leavePolicy, $request->user()), 'Leave policy retired.');
    }

    public function updateWeeklyPattern(Request $request, TenantContext $context, StaffCalendarService $calendar): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['weekdays' => ['required', 'array', 'size:7'], 'weekdays.*' => ['required', Rule::in(['full', 'first_half', 'off'])]]);
        $pattern = [];
        foreach (range(1, 7) as $day) {
            $pattern[$day] = $validated['weekdays'][$day] ?? throw ValidationException::withMessages(['weekdays' => 'Set all seven ISO weekdays (1 = Monday).']);
        }

        return $this->command(fn () => $calendar->setWeeklyPattern($school, $pattern, $request->user()), 'Working week saved.');
    }

    public function storeHoliday(Request $request, TenantContext $context, StaffCalendarService $calendar): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'portion' => ['required', Rule::enum(DayPortion::class)], 'name' => ['required', 'string', 'max:120']]);

        return $this->command(fn () => $calendar->addHoliday($school, $validated['date'], DayPortion::from($validated['portion']), $validated['name'], $request->user()), 'Staff holiday added.');
    }

    public function destroyHoliday(Request $request, TenantContext $context, StaffCalendarService $calendar, string $staffHoliday): RedirectResponse
    {
        $school = $context->requireSchool();

        return $this->command(fn () => $calendar->removeHoliday($school, $staffHoliday, $request->user()), 'Staff holiday removed.');
    }

    public function entitlements(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveLedgerService $ledger): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate(['employment_record_id' => ['sometimes', 'uuid'], 'leave_year_id' => ['sometimes', 'uuid'], 'run_leave_type_id' => ['sometimes', 'uuid']]);
        $years = $reads->leaveYears($school, $actor);
        $yearId = $validated['leave_year_id'] ?? (collect($years)->first(fn (array $y) => $y['startsOn'] <= now()->toDateString() && $y['endsOn'] >= now()->toDateString())['id'] ?? ($years[0]['id'] ?? null));
        $employmentId = $validated['employment_record_id'] ?? null;
        $canManage = $capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school);

        $runPreview = null;
        if ($canManage && isset($validated['run_leave_type_id']) && $yearId !== null) {
            $runPreview = $this->previewRun($ledger, $school, $validated['run_leave_type_id'], $yearId, $actor);
        }

        return Inertia::render('App/Leave/Entitlements', [
            'employments' => $this->employments($context, $school),
            'years' => $years,
            'types' => $reads->types($school, $actor),
            'policies' => $reads->policies($school, null, $actor),
            'employmentRecordId' => $employmentId ?? '',
            'leaveYearId' => $yearId ?? '',
            'assignments' => $employmentId === null ? [] : $reads->assignments($school, $employmentId, $actor),
            'balances' => $employmentId === null || $yearId === null ? [] : $reads->balances($school, $employmentId, $yearId, $actor),
            'ledger' => $employmentId === null || $yearId === null ? [] : $reads->ledger($school, $employmentId, $yearId, $actor),
            'adjustmentReasons' => LeaveLedgerEntry::ADJUSTMENT_REASONS,
            'runPreview' => $runPreview,
            'canManage' => $canManage,
        ]);
    }

    public function storeAssignment(Request $request, TenantContext $context, LeavePolicyAssignmentService $assignments): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_policy_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d']]);

        return $this->command(fn () => $assignments->assign($school, $validated['employment_record_id'], $validated['leave_policy_id'], $validated['effective_from'], $validated['effective_to'] ?? null, $request->user()), 'Policy assigned.');
    }

    public function endAssignment(Request $request, TenantContext $context, LeavePolicyAssignmentService $assignments, string $leavePolicyAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);

        return $this->command(fn () => $assignments->end($school, $leavePolicyAssignment, $validated['effective_to'], $request->user()), 'Policy assignment ended.');
    }

    public function allocate(Request $request, TenantContext $context, LeaveLedgerService $ledger): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'units' => ['required', 'integer', 'min:1', 'max:1000']]);

        return $this->command(fn () => $ledger->allocate($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], (int) $validated['units'], $request->user()), 'Allocation recorded.');
    }

    public function adjust(Request $request, TenantContext $context, LeaveLedgerService $ledger): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['credit', 'debit'])], 'units' => ['required', 'integer', 'min:1', 'max:1000'], 'reason' => ['required', Rule::in(LeaveLedgerEntry::ADJUSTMENT_REASONS)],
        ]);

        return $this->command(fn () => $ledger->adjust($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], $validated['direction'], (int) $validated['units'], $validated['reason'], $request->user()), 'Adjustment recorded.');
    }

    public function executeRun(Request $request, TenantContext $context, LeaveLedgerService $ledger): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);

        return $this->command(fn () => $ledger->executeRun($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user()), 'Annual allocation run executed.');
    }

    public function requests(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveRequestReadService $requests): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate(['status' => ['sometimes', Rule::in(LeaveRequest::STATUSES)]]);
        $rows = $requests->list($school, array_filter(['status' => $validated['status'] ?? null]), $actor);

        return Inertia::render('App/Leave/Requests', [
            'requests' => $rows,
            'status' => $validated['status'] ?? '',
            'statuses' => LeaveRequest::STATUSES,
            'employees' => collect($this->employments($context, $school, array_values(array_unique(array_column($rows, 'employmentRecordId')))))->keyBy('employmentRecordId')->all(),
            'employments' => $this->employments($context, $school),
            'types' => $reads->types($school, $actor),
            'portions' => self::PORTIONS,
            'requestReasons' => LeaveRequest::REASONS,
            'rejectionReasons' => LeaveDecision::REJECTION_REASONS,
            'closingReasons' => LeaveDecision::CLOSING_REASONS,
            'canManage' => $capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school),
        ]);
    }

    public function showRequest(TenantContext $context, LeaveReadService $reads, LeaveRequestReadService $requests, string $leaveRequest): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        abort_if(! Str::isUuid($leaveRequest), 404);
        $detail = $requests->show($school, $leaveRequest, $context->actor());

        return Inertia::render('App/Leave/RequestShow', [
            'request' => $detail,
            'employee' => $this->employments($context, $school, [$detail['employmentRecordId']])[0] ?? null,
            'types' => $reads->types($school, $context->actor()),
            'years' => $reads->leaveYears($school, $context->actor()),
        ]);
    }

    public function submitRequest(Request $request, TenantContext $context, LeaveRequestService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'start_portion' => ['required', Rule::in(self::PORTIONS)],
            'ends_on' => ['required', 'date_format:Y-m-d'], 'end_portion' => ['required', Rule::in(self::PORTIONS)],
            'reason_code' => ['nullable', Rule::in(LeaveRequest::REASONS)],
        ]);

        return $this->command(fn () => $service->submitOnBehalf($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['starts_on'], $validated['start_portion'],
            $validated['ends_on'], $validated['end_portion'], $validated['reason_code'] ?? null, $request->user()), 'Leave request submitted.');
    }

    public function decideRequest(Request $request, TenantContext $context, LeaveRequestService $service, string $leaveRequest, string $action): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($leaveRequest), 404);
        $reasons = match ($action) {
            'approve' => null,
            'reject' => LeaveDecision::REJECTION_REASONS,
            'withdraw', 'cancel' => LeaveDecision::CLOSING_REASONS,
            default => abort(404),
        };
        $reason = $reasons === null ? null : $request->validate(['reason_code' => ['required', Rule::in($reasons)]])['reason_code'];

        return $this->command(fn () => match ($action) {
            'approve' => $service->approve($school, $leaveRequest, $request->user()),
            'reject' => $service->reject($school, $leaveRequest, (string) $reason, $request->user()),
            'withdraw' => $service->withdraw($school, $leaveRequest, (string) $reason, $request->user()),
            'cancel' => $service->cancel($school, $leaveRequest, (string) $reason, $request->user()),
        }, 'Leave request updated.');
    }

    public function yearClose(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveRequestReadService $requests, LeaveYearCloseService $closes): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate(['leave_year_id' => ['sometimes', 'uuid'], 'close_id' => ['sometimes', 'uuid']]);
        $canManage = $capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school);
        $close = isset($validated['close_id']) ? $requests->showClose($school, $validated['close_id'], $actor) : null;

        return Inertia::render('App/Leave/YearClose', [
            'years' => $reads->leaveYears($school, $actor),
            'closes' => $requests->closes($school, $actor),
            'leaveYearId' => $validated['leave_year_id'] ?? '',
            'preview' => $canManage && isset($validated['leave_year_id']) ? $closes->preview($school, $validated['leave_year_id'], $actor) : null,
            'close' => $close,
            'employees' => $close === null ? [] : collect($this->employments($context, $school, array_values(array_unique(array_column($close['items'], 'employmentRecordId')))))->keyBy('employmentRecordId')->all(),
            'types' => $reads->types($school, $actor),
            'canManage' => $canManage,
        ]);
    }

    public function executeYearClose(Request $request, TenantContext $context, LeaveYearCloseService $closes): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_year_id' => ['required', 'uuid']]);

        return $this->command(fn () => $closes->execute($school, $validated['leave_year_id'], $request->user()), 'Leave year closed.');
    }

    /** @return array{candidateCount: int, leaveTypeId: string, error: ?string} */
    private function previewRun(LeaveLedgerService $ledger, School $school, string $leaveTypeId, string $leaveYearId, User $actor): array
    {
        try {
            return ['candidateCount' => count($ledger->runCandidates($school, $leaveTypeId, $leaveYearId, $actor)), 'leaveTypeId' => $leaveTypeId, 'error' => null];
        } catch (LeaveException $e) {
            return ['candidateCount' => 0, 'leaveTypeId' => $leaveTypeId, 'error' => $e->errorCode()];
        } catch (ModelNotFoundException) {
            return ['candidateCount' => 0, 'leaveTypeId' => $leaveTypeId, 'error' => 'not_found'];
        }
    }
}
