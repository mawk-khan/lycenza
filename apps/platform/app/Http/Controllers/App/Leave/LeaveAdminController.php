<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\HR\Application\EmployeeCategoryReference;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Application\Accrual\LeaveAccrualTiming;
use App\Domain\Leave\Application\Accrual\LeaveEntitlementMethod;
use App\Domain\Leave\Application\Accrual\LeaveStatementReader;
use App\Domain\Leave\Application\Calendar\CalendarChangeReason;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
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
use App\Domain\Leave\Http\Requests\LeavePolicyInput;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
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

    public function configuration(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, EmployeeCategoryReference $categories, DepartmentReference $departments, FreshMfaRequirement $mfa, StaffCalendarService $calendar): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $today = SchoolTimezone::today($school);

        return Inertia::render('App/Leave/Configuration', [
            'settings' => $reads->settings($school, $actor),
            'years' => $reads->leaveYears($school, $actor),
            'types' => $reads->types($school, $actor),
            'policies' => $reads->policies($school, null, $actor),
            'calendar' => $reads->calendar($school, CarbonImmutable::parse($today)->subYear()->toDateString(), CarbonImmutable::parse($today)->addYears(2)->toDateString(), $actor),
            'portions' => self::PORTIONS,
            'canConfigure' => $capabilities->canInSchool($actor, LeaveCapabilities::CONFIGURE, $school),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
            // HR.R1-C22 (D6-4, D14-08): what a change would touch, asked for BEFORE saving (a partial reload only).
            'calendarImpact' => Inertia::optional(fn () => $this->calendarImpactPreview($request, $calendar, $school, $actor)),
            // HR.9: the eligibility dimensions a version's rules may name -- the closed staff types and HR's active reference data.
            'staffTypes' => StaffType::options(),
            'employeeCategories' => $context->withSchool($school, fn () => $categories->options($school)),
            'departments' => $context->withSchool($school, fn () => $departments->options($school)),
            'today' => SchoolTimezone::today($school),
            // HR.10: the closed entitlement methods and accrual timings a version may use.
            'entitlementMethods' => LeaveEntitlementMethod::options(),
            'accrualTimings' => LeaveAccrualTiming::options(),
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
        // HR.9 (ADR 0065 §28): one input shape with the API -- terms, request-timing rules, eligibility rules.
        $validated = $request->validate(LeavePolicyInput::rules());

        return $this->command(fn () => $policies->create($school, $validated['leave_type_id'], LeavePolicyInput::terms($validated), $validated['supersedes_policy_id'] ?? null,
            $request->user(), LeavePolicyInput::criteria($validated)), 'Leave policy created.');
    }

    public function retirePolicy(Request $request, TenantContext $context, LeavePolicyService $policies, string $leavePolicy): RedirectResponse
    {
        $school = $context->requireSchool();

        return $this->command(fn () => $policies->retire($school, $leavePolicy, $request->user()), 'Leave policy retired.');
    }

    /**
     * HR.R1-C22 (WO-5, D-6): the week in force from a date (today by default). A past date is a
     * correction: the capability first, then a closed reason and the actor's own fresh MFA (rule 95).
     */
    public function updateWeeklyPattern(Request $request, TenantContext $context, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'weekdays' => ['required', 'array', 'size:7'], 'weekdays.*' => ['required', Rule::in(['full', 'first_half', 'off'])],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $pattern = [];
        foreach (range(1, 7) as $day) {
            $pattern[$day] = $validated['weekdays'][$day] ?? throw ValidationException::withMessages(['weekdays' => 'Set all seven ISO weekdays (1 = Monday).']);
        }
        $from = $validated['effective_from'] ?? $history->today($school);
        [$reason, $proof] = $this->calendarCorrection($request, $school, $from, false, $history, $mfa);

        $response = $this->command(fn () => $calendar->recordWeek($school, $from, $pattern, $request->user(), $reason, $proof), 'Working week saved.');

        return $response->with('status', "Working week saved from {$from}.".$this->calendarNotice($calendar, $school, $from, null, $request->user()));
    }

    /** A scheduled week that has not taken effect is withdrawn. */
    public function withdrawWeek(Request $request, TenantContext $context, StaffCalendarService $calendar, string $staffWorkingWeekVersion): RedirectResponse
    {
        $school = $context->requireSchool();

        return $this->command(fn () => $calendar->withdrawWeek($school, $staffWorkingWeekVersion, $request->user()), 'Scheduled working week withdrawn.');
    }

    public function storeHoliday(Request $request, TenantContext $context, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'portion' => ['required', Rule::enum(DayPortion::class)], 'name' => ['required', 'string', 'max:120']]);
        [$reason, $proof] = $this->calendarCorrection($request, $school, $validated['date'], false, $history, $mfa);

        $response = $this->command(fn () => $calendar->addHoliday($school, $validated['date'], DayPortion::from($validated['portion']), $validated['name'], $request->user(), $reason, $proof), 'Staff holiday added.');

        return $response->with('status', 'Staff holiday added.'.$this->calendarNotice($calendar, $school, $validated['date'], $validated['date'], $request->user()));
    }

    /** S-16: a holiday is withdrawn, never deleted. One that has taken effect needs a reason and a fresh code. */
    public function destroyHoliday(Request $request, TenantContext $context, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa, string $staffHoliday): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($staffHoliday), 404);
        // The capability before any lookup: a holiday's existence is not answered to someone who may not configure (C14-N1).
        $this->authorizeCapabilityFor($request->user(), LeaveCapabilities::CONFIGURE, $school);
        $holiday = $context->withSchool($school, fn () => StaffHoliday::query()->where('school_id', $school->id)->find($staffHoliday));
        abort_if($holiday === null, 404);
        $date = $holiday->holiday_on->toDateString();
        [$reason, $proof] = $this->calendarCorrection($request, $school, $date, true, $history, $mfa);

        $response = $this->command(fn () => $calendar->withdrawHoliday($school, $staffHoliday, $request->user(), $reason, $proof), 'Staff holiday withdrawn.');

        return $response->with('status', 'Staff holiday withdrawn.'.$this->calendarNotice($calendar, $school, $date, $date, $request->user()));
    }

    /**
     * D6-5: a change dated before the School's today (or, for a withdrawal, on or before it) is a
     * correction. The capability is checked first, then the reason, then the fresh code.
     *
     * @return array{0: ?CalendarChangeReason, 1: ?FreshMfaProof}
     */
    private function calendarCorrection(Request $request, School $school, string $date, bool $includingToday, StaffCalendarHistory $history, FreshMfaRequirement $mfa): array
    {
        $today = $history->today($school);
        if ($includingToday ? $date > $today : $date >= $today) {
            return [null, null];
        }
        $this->authorizeCapabilityFor($request->user(), LeaveCapabilities::CONFIGURE, $school);
        $reason = $request->validate(['reason_code' => ['required', Rule::enum(CalendarChangeReason::class)]], ['reason_code.required' => 'Choose why this past date is being corrected.'])['reason_code'];

        return [CalendarChangeReason::from($reason), $mfa->requireForAction($request, $request->user())];
    }

    /** D6-4 (D14-08): what the saved change touches. Counts only; nothing is recalculated. */
    private function calendarNotice(StaffCalendarService $calendar, School $school, string $from, ?string $to, User $actor): string
    {
        $to ??= CarbonImmutable::parse($from)->addYear()->toDateString();
        $touched = array_filter($calendar->impact($school, $from, $to, $actor), fn (array $i) => $i['count'] > 0);

        return $touched === [] ? '' : ' It touches '.implode(', ', array_map(fn (array $i) => "{$i['count']} {$i['label']}", $touched)).'; nothing is recalculated.';
    }

    /**
     * D6-4 (D14-08): the same counts as the saved notice, for a range the page names before saving. A week
     * reaches a year from its date, a holiday its own day; never more than a year and a day. `impact()`
     * authorizes `hr.leave.configure`.
     *
     * @return array{from: string, to: string, touched: list<array{label: string, count: int}>}
     */
    private function calendarImpactPreview(Request $request, StaffCalendarService $calendar, School $school, User $actor): array
    {
        $validated = $request->validate(['impact_from' => ['required', 'date_format:Y-m-d'], 'impact_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:impact_from']]);
        $from = $validated['impact_from'];
        $to = $validated['impact_to'] ?? CarbonImmutable::parse($from)->addYear()->toDateString();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ValidationException::withMessages(['impact_to' => 'Ask about at most a year at a time.']);
        }

        return ['from' => $from, 'to' => $to, 'touched' => $calendar->impact($school, $from, $to, $actor)];
    }

    public function entitlements(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveLedgerService $ledger, LeaveStatementReader $statements, FreshMfaRequirement $mfa): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate(['employment_record_id' => ['sometimes', 'uuid'], 'leave_year_id' => ['sometimes', 'uuid'], 'run_leave_type_id' => ['sometimes', 'uuid']]);
        $years = $reads->leaveYears($school, $actor);
        $today = SchoolTimezone::today($school);
        $yearId = $validated['leave_year_id'] ?? (collect($years)->first(fn (array $y) => $y['startsOn'] <= $today && $y['endsOn'] >= $today)['id'] ?? ($years[0]['id'] ?? null));
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
            // HR.10 (D11-16): each funded type's statement -- the balance identity over every entry, from the server.
            'statements' => $employmentId === null || $yearId === null ? [] : array_map(
                fn (array $b) => $statements->statement($school, $employmentId, $b['leaveTypeId'], $yearId, $actor),
                $reads->balances($school, $employmentId, $yearId, $actor),
            ),
            'mfaEnrolled' => $mfa->hasActiveFactor($actor),
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

    /** HR.R1-C35 WO-19 (D-4): an allocation needs `hr.leave.manage`, then a fresh MFA code (outside the transaction). */
    public function allocate(Request $request, TenantContext $context, LeaveLedgerService $ledger, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'units' => ['required', 'integer', 'min:1', 'max:1000']]);
        $this->authorizeCapability(LeaveCapabilities::MANAGE, $school);
        $proof = $mfa->requireForAction($request, $request->user());

        return $this->command(fn () => $ledger->allocate($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], (int) $validated['units'], $request->user(), $proof), 'Allocation recorded.');
    }

    /** HR.10 (S-7): an adjustment needs `hr.leave.manage`, then a fresh MFA code (outside the transaction). */
    public function adjust(Request $request, TenantContext $context, LeaveLedgerService $ledger, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['credit', 'debit'])], 'units' => ['required', 'integer', 'min:1', 'max:1000'], 'reason' => ['required', Rule::in(LeaveLedgerEntry::ADJUSTMENT_REASONS)],
            'corrects_entry_id' => ['nullable', 'uuid'],
        ]);
        $this->authorizeCapability(LeaveCapabilities::MANAGE, $school);
        $proof = $mfa->requireForAction($request, $request->user());

        return $this->command(fn () => $ledger->adjust($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], $validated['direction'], (int) $validated['units'], $validated['reason'], $request->user(), $proof,
            isset($validated['corrects_entry_id']) ? strtolower((string) $validated['corrects_entry_id']) : null), 'Adjustment recorded.');
    }

    /** HR.10 (D11-01, S-7): a go-live opening balance -- `hr.leave.manage`, then a fresh MFA code. */
    public function opening(Request $request, TenantContext $context, LeaveLedgerService $ledger, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['employment_record_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'units' => ['required', 'integer', 'min:1', 'max:1000']]);
        $this->authorizeCapability(LeaveCapabilities::MANAGE, $school);
        $proof = $mfa->requireForAction($request, $request->user());

        return $this->command(fn () => $ledger->recordOpening($school, $validated['employment_record_id'], $validated['leave_type_id'], $validated['leave_year_id'], (int) $validated['units'], $request->user(), $proof), 'Opening balance recorded.');
    }

    /** HR.R1-C35 WO-19 (D-4): the annual run needs `hr.leave.manage`, then a fresh MFA code (outside the transaction). */
    public function executeRun(Request $request, TenantContext $context, LeaveLedgerService $ledger, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid']]);
        $this->authorizeCapability(LeaveCapabilities::MANAGE, $school);
        $proof = $mfa->requireForAction($request, $request->user());

        return $this->command(fn () => $ledger->executeRun($school, $validated['leave_type_id'], $validated['leave_year_id'], $request->user(), $proof), 'Annual allocation run executed.');
    }

    public function requests(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveRequestReadService $requests): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(LeaveRequest::STATUSES)],
            // ORG.9C-1 (ADR 0076 §69), HR-043: narrowing filters only.
            'employment_record_id' => ['sometimes', 'nullable', 'uuid'],
            'leave_type_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $filters = array_filter([
            'status' => $validated['status'] ?? null,
            'employment_record_id' => $validated['employment_record_id'] ?? null,
            'leave_type_id' => $validated['leave_type_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);
        $rows = $requests->list($school, $filters, $actor);

        return Inertia::render('App/Leave/Requests', [
            'requests' => $rows,
            'status' => $validated['status'] ?? '',
            'filters' => [
                'employment_record_id' => $filters['employment_record_id'] ?? '',
                'leave_type_id' => $filters['leave_type_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
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

    /**
     * ORG.9C-1 (ADR 0076 §69), HR-042: approved leave taken in one month per
     * employment and leave type (half-day units), whole-School, read-only.
     * Never a reason, never paid/unpaid.
     */
    public function monthly(Request $request, TenantContext $context, LeaveReadService $reads, LeaveRequestReadService $requests): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $month = $request->validate(['month' => ['sometimes', 'date_format:Y-m']])['month'] ?? substr(SchoolTimezone::today($school), 0, 7);
        $summary = $requests->monthSummary($school, $month, $context->actor());

        return Inertia::render('App/Leave/Monthly', [
            'summary' => $summary,
            'employees' => collect($this->employments($context, $school, array_column($summary['rows'], 'employmentRecordId')))->keyBy('employmentRecordId')->all(),
            'types' => $reads->types($school, $context->actor()),
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
