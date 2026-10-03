<?php

namespace App\Domain\StaffAttendance\Application;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveCoverageReader;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * HRX.3 (ADR 0065 §24.3, §24.11): the composed Staff Attendance view, under
 * `hr.staff_attendance.view` -- the daily register, one employment's
 * history, and one record with its correction evidence. Suitable for the
 * administrative UI, the API and HRX.4's future own-attendance view; it is
 * not coupled to any page.
 *
 * Per half, the EFFECTIVE state is derived, never stored:
 * - `leave`      approved leave's day evidence covers the half;
 * - `present` / `absent`  the recorded evidence;
 * - `holiday`    a staff holiday covers it in today's calendar;
 * - `off_day`    the weekly pattern makes it non-working today;
 * - `unrecorded` working time with no evidence.
 *
 * Every layer stays visible: the recorded evidence (`recorded`) is returned
 * beside the effective state, so absence recorded before an approval
 * reappears when that leave is cancelled; and a recorded half stays
 * effective even when today's calendar calls it a holiday (`calendar`
 * shows today's classification). Leave detail is minimal -- `onLeave`, and
 * the request id and leave-type name only for a reader who also holds
 * `hr.leave.view`; never a reason, decision, approver, policy or balance.
 */
class StaffAttendanceReadService
{
    use AuthorizesCapability;

    public const MAX_HISTORY_DAYS = 93;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmploymentRoster $roster,
        private readonly StaffCalendarService $calendar,
        private readonly LeaveCoverageReader $leave,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /**
     * The School's daily register: every employment spanning the date, plus
     * any employment holding a record on it, with its composed day.
     *
     * @return array{date: string, calendarConfigured: bool, calendar: ?array{firstHalf: string, secondHalf: string}, rows: list<array<string, mixed>>}
     */
    public function register(School $school, string $date, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::VIEW, $school);
        self::requireDate($date);

        $roster = collect($this->roster->on($school, $date))->keyBy('employmentRecordId');
        $records = $this->context->withSchool($school, fn () => StaffAttendanceRecord::query()->where('school_id', $school->id)
            ->where('attendance_date', $date)->get()->keyBy('employment_record_id'));
        $missing = array_values(array_diff($records->keys()->all(), $roster->keys()->all()));
        $labels = $roster->merge(collect($this->roster->labels($school, $missing))->keyBy('employmentRecordId'))
            ->sortBy(fn (array $row) => [$row['fullName'] ?? '', $row['employmentRecordId']]);
        $calendar = $this->calendarHalves($school, $date, $date)[$date] ?? null;
        $coverage = $this->leave->approvedCoverage($school, $labels->keys()->all(), $date, $date);
        $detail = $this->leaveDetail($school, $actor);

        return [
            'date' => $date,
            'calendarConfigured' => $calendar !== null,
            'calendar' => $calendar === null ? null : ['firstHalf' => $calendar[1], 'secondHalf' => $calendar[2]],
            'rows' => $labels->map(fn (array $label) => [
                'employment' => self::label($label),
                'day' => self::day($date, $records[$label['employmentRecordId']] ?? null, $calendar, $coverage[$label['employmentRecordId']][$date] ?? [], $detail),
            ])->values()->all(),
        ];
    }

    /**
     * One employment's composed days over [from, to] (at most MAX_HISTORY_DAYS).
     *
     * @return array<string, mixed>
     */
    public function history(School $school, string $employmentRecordId, string $from, string $to, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::VIEW, $school);
        self::requireDate($from);
        self::requireDate($to);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        if ($from > $to || $start->diffInDays($end) >= self::MAX_HISTORY_DAYS) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_RANGE_INVALID', 'Choose a range of 1 to '.self::MAX_HISTORY_DAYS.' days, from before to.');
        }
        $label = $this->roster->labels($school, [$employmentRecordId])[0] ?? throw new ModelNotFoundException('No query results for the employment.');

        $records = $this->context->withSchool($school, fn () => StaffAttendanceRecord::query()->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)->whereBetween('attendance_date', [$from, $to])->get()
            ->keyBy(fn (StaffAttendanceRecord $r) => $r->attendance_date->toDateString()));
        $calendar = $this->calendarHalves($school, $from, $to);
        $coverage = $this->leave->approvedCoverage($school, [$employmentRecordId], $from, $to)[$employmentRecordId] ?? [];
        $detail = $this->leaveDetail($school, $actor);

        $days = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $days[] = self::day($date, $records[$date] ?? null, $calendar === [] ? null : $calendar[$date], $coverage[$date] ?? [], $detail);
        }

        return ['employment' => self::label($label), 'from' => $from, 'to' => $to, 'calendarConfigured' => $calendar !== [], 'days' => $days];
    }

    /** @return array<string, mixed> one record with its append-only correction history, oldest first */
    public function show(School $school, string $recordId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $recordId) {
            $record = StaffAttendanceRecord::query()->where('school_id', $school->id)->findOrFail($recordId);

            return self::record($record) + [
                'corrections' => StaffAttendanceCorrection::query()->where('school_id', $school->id)->where('staff_attendance_record_id', $record->id)
                    ->orderBy('from_version')->get()->map(fn (StaffAttendanceCorrection $c) => self::correction($c))->values()->all(),
            ];
        });
    }

    /**
     * The correction evidence of several records (an employment's history page), oldest first per record.
     *
     * @param  list<string>  $recordIds
     * @return array<string, list<array<string, mixed>>> record id => corrections
     */
    public function corrections(School $school, array $recordIds, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::VIEW, $school);
        if ($recordIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn () => StaffAttendanceCorrection::query()->where('school_id', $school->id)
            ->whereIn('staff_attendance_record_id', $recordIds)->orderBy('from_version')->get()
            ->groupBy('staff_attendance_record_id')->map(fn ($rows) => $rows->map(fn (StaffAttendanceCorrection $c) => self::correction($c))->values()->all())->all());
    }

    /** @return array<string, mixed> the stored evidence, as written (no derived state) */
    public static function record(StaffAttendanceRecord $r): array
    {
        return [
            'id' => $r->id, 'employmentRecordId' => $r->employment_record_id, 'employeeId' => $r->employee_id,
            'date' => $r->attendance_date->toDateString(), 'firstHalf' => $r->first_half_status, 'secondHalf' => $r->second_half_status,
            'version' => $r->version, 'recordedAt' => $r->created_at?->toIso8601String(), 'updatedAt' => $r->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function correction(StaffAttendanceCorrection $c): array
    {
        return [
            'id' => $c->id, 'fromVersion' => $c->from_version, 'toVersion' => $c->to_version,
            'before' => ['firstHalf' => $c->before_first_half_status, 'secondHalf' => $c->before_second_half_status],
            'after' => ['firstHalf' => $c->after_first_half_status, 'secondHalf' => $c->after_second_half_status],
            'reasonCode' => $c->reason_code, 'correctedAt' => $c->created_at->toIso8601String(),
        ];
    }

    /**
     * The daily summary over the halves that are not holiday/off-day.
     *
     * @param  array{0: string, 1: string}  $states  effective first- and second-half states
     */
    public static function summary(array $states): string
    {
        $relevant = array_values(array_diff($states, ['holiday', 'off_day']));
        if ($relevant === []) {
            return in_array('holiday', $states, true) ? 'holiday' : 'off_day';
        }
        $distinct = array_values(array_unique($relevant));
        sort($distinct);

        return match (true) {
            $distinct === ['present'] => 'present',
            $distinct === ['absent'] => 'absent',
            $distinct === ['leave'] => 'on_leave',
            $distinct === ['unrecorded'] => 'unrecorded',
            $distinct === ['absent', 'present'] => 'half_day_absent',
            in_array('unrecorded', $distinct, true) => 'partially_recorded',
            default => 'mixed',
        };
    }

    /**
     * @param  array{1: string, 2: string}|null  $calendar  today's classification of each half (null: calendar not configured)
     * @param  array<int, array{leaveRequestId: string, leaveTypeId: string, leaveTypeCode: string, leaveTypeName: string}>  $coverage
     * @return array<string, mixed>
     */
    private static function day(string $date, ?StaffAttendanceRecord $record, ?array $calendar, array $coverage, bool $leaveDetail): array
    {
        $halves = [];
        foreach ([1, 2] as $half) {
            $recorded = $record?->halves()[$half];
            $today = $calendar[$half] ?? null;
            $leave = $coverage[$half] ?? null;
            $halves[$half] = [
                'state' => match (true) {
                    $leave !== null => 'leave',
                    $recorded !== null => $recorded,
                    $today === 'holiday' => 'holiday',
                    $today === 'off' => 'off_day',
                    default => 'unrecorded',
                },
                'recorded' => $recorded,
                'calendar' => $today,
                'onLeave' => $leave !== null,
                'leave' => $leave !== null && $leaveDetail ? ['leaveRequestId' => $leave['leaveRequestId'], 'leaveTypeName' => $leave['leaveTypeName']] : null,
            ];
        }

        return [
            'date' => $date,
            'record' => $record === null ? null : ['id' => $record->id, 'version' => $record->version],
            'firstHalf' => $halves[1],
            'secondHalf' => $halves[2],
            'summary' => self::summary([$halves[1]['state'], $halves[2]['state']]),
        ];
    }

    /**
     * @param  array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool}  $label
     * @return array<string, mixed>
     */
    private static function label(array $label): array
    {
        return [
            'employmentRecordId' => $label['employmentRecordId'], 'employeeId' => $label['employeeId'],
            'employeeNumber' => $label['employeeNumber'], 'fullName' => $label['fullName'],
            'status' => $label['status'], 'recordable' => $label['current'],
        ];
    }

    /** @return array<string, array{1: string, 2: string}> date => today's half classification; empty while the calendar is unconfigured */
    private function calendarHalves(School $school, string $from, string $to): array
    {
        try {
            $calculator = $this->calendar->calculator($school, $from, $to);
        } catch (LeaveException) {
            return [];
        }
        $halves = [];
        for ($d = CarbonImmutable::createFromFormat('!Y-m-d', $from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $halves[$d->toDateString()] = $calculator->halves($d->toDateString());
        }

        return $halves;
    }

    /** ADR 0065 §24.11: leave identifiers only for a reader who may read leave records anyway. */
    private function leaveDetail(School $school, User $actor): bool
    {
        return $this->capabilities->canInSchool($actor, LeaveCapabilities::VIEW, $school);
    }

    private static function requireDate(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_DATE_INVALID', 'Give the date as YYYY-MM-DD.');
        }
    }
}
