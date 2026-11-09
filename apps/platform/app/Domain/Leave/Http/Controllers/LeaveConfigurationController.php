<?php

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Leave\Application\Calendar\CalendarChangeReason;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Http\Requests\LeavePolicyInput;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekVersion;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HRX.1 (ADR 0065 §4) -- Leave configuration API: leave-year settings and
 * years, leave types, leave policies, the staff working calendar. Thin:
 * validate -> delegate -> present. Every service authorizes its own
 * capability (`hr.leave.configure` / `.view`); route middleware is the outer
 * layer. `school_id` is never read from the body.
 */
class LeaveConfigurationController extends Controller
{
    use NormalizesCodeInput;

    public function settings(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->settings($school, $request->user())]);
    }

    public function updateSettings(Request $request, School $school, LeaveYearService $years, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['leave_year_start_month' => ['required', 'integer', 'between:1,12']]);
        $years->setStartMonth($school, (int) $validated['leave_year_start_month'], $request->user());

        return response()->json(['data' => $reads->settings($school, $request->user(), LeaveCapabilities::CONFIGURE)]);
    }

    public function scheduleStartChange(Request $request, School $school, LeaveYearService $years, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'start_month' => ['required', 'integer', 'between:1,12'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
        $years->scheduleStartChange($school, (int) $validated['start_month'], $validated['effective_from'], $request->user());

        return response()->json(['data' => $reads->settings($school, $request->user(), LeaveCapabilities::CONFIGURE)], 201);
    }

    public function years(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->leaveYears($school, $request->user())]);
    }

    public function openYear(Request $request, School $school, LeaveYearService $years): JsonResponse
    {
        $validated = $request->validate(['on' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => LeaveReadService::year($years->open($school, $validated['on'], $request->user()))], 201);
    }

    public function types(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->types($school, $request->user())]);
    }

    public function storeType(Request $request, School $school, LeaveTypeService $types): JsonResponse
    {
        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('leave_types', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:120'],
            'is_paid' => ['required', 'boolean'],
            'tracks_balance' => ['required', 'boolean'],
            'allows_half_day' => ['required', 'boolean'],
        ]);
        $type = $types->create($school, $validated['code'], $validated['name'], (bool) $validated['is_paid'], (bool) $validated['tracks_balance'], (bool) $validated['allows_half_day'], $request->user());

        return response()->json(['data' => LeaveReadService::type($type)], 201);
    }

    public function updateType(Request $request, School $school, string $leaveType, LeaveTypeService $types): JsonResponse
    {
        abort_if(! Str::isUuid($leaveType), 404);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_paid' => ['sometimes', 'boolean'],
            'tracks_balance' => ['sometimes', 'boolean'],
            'allows_half_day' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => LeaveReadService::type($types->update($school, $leaveType, $validated, $request->user()))]);
    }

    public function setTypeStatus(Request $request, School $school, string $leaveType, LeaveTypeService $types): JsonResponse
    {
        abort_if(! Str::isUuid($leaveType), 404);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return response()->json(['data' => LeaveReadService::type($types->setStatus($school, $leaveType, $validated['status'], $request->user()))]);
    }

    public function policies(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['leave_type_id' => ['sometimes', 'uuid']]);

        return response()->json(['data' => $reads->policies($school, $validated['leave_type_id'] ?? null, $request->user())]);
    }

    public function storePolicy(Request $request, School $school, LeavePolicyService $policies): JsonResponse
    {
        $validated = $request->validate(LeavePolicyInput::rules());
        $policy = $policies->create($school, $validated['leave_type_id'], LeavePolicyInput::terms($validated), $validated['supersedes_policy_id'] ?? null,
            $request->user(), LeavePolicyInput::criteria($validated));

        return response()->json(['data' => LeaveReadService::policy($policy, LeaveReadService::rulesOf($policy))], 201);
    }

    public function retirePolicy(Request $request, School $school, string $leavePolicy, LeavePolicyService $policies): JsonResponse
    {
        abort_if(! Str::isUuid($leavePolicy), 404);

        return response()->json(['data' => LeaveReadService::policy($policies->retire($school, $leavePolicy, $request->user()))]);
    }

    public function calendar(Request $request, School $school, LeaveReadService $reads): JsonResponse
    {
        $validated = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $reads->calendar($school, $validated['from'], $validated['to'], $request->user())]);
    }

    /** HR.R1-C22 (WO-5, D-6): the week in force from a date (today by default); a past date is a correction (D6-5). */
    public function updateWeeklyPattern(Request $request, School $school, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa): JsonResponse
    {
        $validated = $request->validate([
            'weekdays' => ['required', 'array', 'size:7'],
            'weekdays.*.iso_weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'weekdays.*.portion' => ['required', Rule::in(StaffWorkingWeekVersion::PORTIONS)],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $validated['effective_from'] ?? $history->today($school);
        [$reason, $proof] = $this->correction($request, $school, $from, false, $history, $mfa);
        $version = $calendar->recordWeek($school, $from, collect($validated['weekdays'])->mapWithKeys(fn ($d) => [(int) $d['iso_weekday'] => $d['portion']])->all(), $request->user(), $reason, $proof);

        return response()->json(['data' => ['weeklyPattern' => $calendar->pattern($school), 'version' => LeaveReadService::weekVersion($version)]]);
    }

    public function storeHoliday(Request $request, School $school, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'portion' => ['required', Rule::enum(DayPortion::class)],
            'name' => ['required', 'string', 'max:120'],
        ]);
        [$reason, $proof] = $this->correction($request, $school, $validated['date'], false, $history, $mfa);

        return response()->json(['data' => LeaveReadService::holiday($calendar->addHoliday($school, $validated['date'], DayPortion::from($validated['portion']), $validated['name'], $request->user(), $reason, $proof))], 201);
    }

    /** S-16: withdraws the holiday (never deletes it). One that has taken effect needs a reason and a fresh code. */
    public function destroyHoliday(Request $request, School $school, string $staffHoliday, StaffCalendarService $calendar, StaffCalendarHistory $history, FreshMfaRequirement $mfa, TenantContext $context): JsonResponse
    {
        abort_if(! Str::isUuid($staffHoliday), 404);
        $holiday = $context->withSchool($school, fn () => StaffHoliday::query()->where('school_id', $school->id)->find($staffHoliday));
        abort_if($holiday === null, 404);
        [$reason, $proof] = $this->correction($request, $school, $holiday->holiday_on->toDateString(), true, $history, $mfa);
        $calendar->withdrawHoliday($school, $staffHoliday, $request->user(), $reason, $proof);

        return response()->json(null, 204);
    }

    /**
     * D6-5: a change dated before the School's today (a withdrawal: on or before it) needs a closed reason and the
     * actor's own fresh code; the route's capability middleware has already run (rule 95).
     *
     * @return array{0: ?CalendarChangeReason, 1: ?FreshMfaProof}
     */
    private function correction(Request $request, School $school, string $date, bool $includingToday, StaffCalendarHistory $history, FreshMfaRequirement $mfa): array
    {
        $today = $history->today($school);
        if ($includingToday ? $date > $today : $date >= $today) {
            return [null, null];
        }
        $reason = $request->validate(['reason_code' => ['required', Rule::enum(CalendarChangeReason::class)]])['reason_code'];

        return [CalendarChangeReason::from($reason), $mfa->requireForAction($request, $request->user())];
    }
}
