<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Calendar\CalendarChangeImpactSource;
use App\Domain\Leave\Application\Calendar\CalendarChangeReason;
use App\Domain\Leave\Application\Calendar\PastCalendarChangeGate;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekVersion;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HRX.1 (ADR 0065 §4.7): the staff working calendar Leave owns -- a weekly
 * working pattern (each ISO weekday `full`, `first_half` or `off`) and
 * dated staff holidays. No School calendar exists elsewhere to reuse; this
 * one is staff-only and never touches an academic calendar, timetable or
 * Student attendance.
 *
 * HR.R1-C22 (WO-5, D-6, ADR 0065 §32): the one writer of an effective-dated calendar.
 * - A week is recorded as a version from a date; a holiday is added or withdrawn. Nothing is
 *   overwritten or deleted (S-16).
 * - Ordinary changes are today or later. A past-dated change is a correction (D6-2, D6-5): a closed
 *   reason, the actor's own fresh MFA proof, the capability re-read under the calendar lock, and
 *   refused if it reaches a month whose Staff Attendance is locked.
 * - Lock order: School (FOR SHARE) -> the touched months' keys shared (past-dated only, through
 *   Staff Attendance's gate) -> `LeaveLocks::calendar` exclusive -> rows.
 * - "Past" is the School's date on the database clock, the clock its guards use.
 * - Approved leave and Payroll snapshots never change; a saved change reports what it touches.
 */
class StaffCalendarService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly StaffCalendarHistory $history,
        private readonly PastCalendarChangeGate $gate,
        private readonly ApprovalDecisionAuthority $authority,
    ) {}

    /**
     * Records the week in force from `$effectiveFrom`. A version of the same date is replaced
     * (superseded), never edited.
     *
     * @param  array<int, string>  $pattern  ISO weekday 1..7 => full|first_half|off, all seven
     */
    public function recordWeek(School $school, string $effectiveFrom, array $pattern, User $actor, ?CalendarChangeReason $reason = null, ?FreshMfaProof $mfa = null): StaffWorkingWeekVersion
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        ksort($pattern);
        if (array_keys($pattern) !== [1, 2, 3, 4, 5, 6, 7] || array_diff($pattern, StaffWorkingWeekVersion::PORTIONS) !== []) {
            throw LeaveException::invalid('LEAVE_CALENDAR_PATTERN_INVALID', 'Give every ISO weekday 1..7 exactly once, each full, first_half or off.');
        }
        self::requireDate($effectiveFrom);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $effectiveFrom, $pattern, $actor, $reason, $mfa) {
            $this->guard->requireOperational($school->id);
            $today = $this->history->today($school);
            $past = $effectiveFrom < $today;
            if ($past) {
                $this->requireCorrection($actor, $reason, $mfa);
                $next = StaffWorkingWeekVersion::query()->where('school_id', $school->id)->where('status', StaffWorkingWeekVersion::ACTIVE)
                    ->where('effective_from', '>', $effectiveFrom)->min('effective_from');
                $until = $next === null ? $today : CarbonImmutable::parse((string) $next)->subDay()->toDateString();
                $this->gate->holdOpenMonths($school, $effectiveFrom, min($until, CarbonImmutable::parse($today)->subDay()->toDateString()));
            }
            LeaveLocks::calendar($school, shared: false);
            if ($past) {
                $this->authority->requireCurrent($actor, 'hr.leave.configure', $school);
            }
            $this->refuseBeforeHistory($school, $effectiveFrom, $past);
            $replaced = StaffWorkingWeekVersion::query()->where('school_id', $school->id)->where('status', StaffWorkingWeekVersion::ACTIVE)
                ->where('effective_from', $effectiveFrom)->lockForUpdate()->first();

            $version = StaffWorkingWeekVersion::query()->forceCreate([
                'school_id' => $school->id, 'effective_from' => $effectiveFrom,
                ...array_combine(array_map(fn (int $d) => "weekday_{$d}", range(1, 7)), array_values($pattern)),
                'status' => StaffWorkingWeekVersion::ACTIVE, 'supersedes_id' => $replaced?->id, 'reason_code' => $past ? $reason?->value : null,
                'recorded_by_user_id' => $actor->id, 'recorded_at' => now(),
            ]);
            $this->audit->school($school, 'leave.calendar.week_recorded', actor: $actor, subject: $version, metadata: [
                'effectiveFrom' => $effectiveFrom, 'pattern' => $pattern, 'supersedes' => $replaced?->id,
                'pastDated' => $past, 'reasonCode' => $past ? $reason?->value : null, ...($past ? ['mfa' => 'fresh'] : []),
            ]);

            return $version->refresh();
        }));
    }

    /** A version that has not taken effect is withdrawn; one in force is replaced by a correction instead. */
    public function withdrawWeek(School $school, string $versionId, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $versionId, $actor) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::calendar($school, shared: false);
            $version = StaffWorkingWeekVersion::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($versionId);
            if ($version->status !== StaffWorkingWeekVersion::ACTIVE || $version->effective_from->toDateString() <= $this->history->today($school)) {
                throw LeaveException::conflict('LEAVE_CALENDAR_WITHDRAW_EFFECTIVE', 'Only a scheduled week that has not taken effect can be withdrawn. Record a correction instead.');
            }
            $version->forceFill(['status' => StaffWorkingWeekVersion::WITHDRAWN, 'status_changed_by_user_id' => $actor->id, 'status_changed_at' => now()])->save();
            $this->audit->school($school, 'leave.calendar.week_withdrawn', actor: $actor, subject: $version, metadata: ['effectiveFrom' => $version->effective_from->toDateString()]);
        }));
    }

    /** A today-dated week: the ordinary change (HRX.1 callers). */
    public function setWeeklyPattern(School $school, array $pattern, User $actor): void
    {
        $this->recordWeek($school, $this->history->today($school), $pattern, $actor);
    }

    public function addHoliday(School $school, string $date, DayPortion $portion, string $name, User $actor, ?CalendarChangeReason $reason = null, ?FreshMfaProof $mfa = null): StaffHoliday
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        self::requireDate($date);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $portion, $name, $actor, $reason, $mfa) {
            $this->guard->requireOperational($school->id);
            $past = $date < $this->history->today($school);
            if ($past) {
                $this->requireCorrection($actor, $reason, $mfa);
                $this->gate->holdOpenMonths($school, $date, $date);
            }
            // Every calendar writer holds the calendar lock, so an approval reads one consistent calendar (ADR 0065 §23.10).
            LeaveLocks::calendar($school, shared: false);
            if ($past) {
                $this->authority->requireCurrent($actor, 'hr.leave.configure', $school);
            }
            $this->refuseBeforeHistory($school, $date, $past);
            if (StaffHoliday::query()->where('school_id', $school->id)->where('status', StaffHoliday::ACTIVE)->where('holiday_on', $date)->exists()) {
                throw LeaveException::conflict('LEAVE_HOLIDAY_EXISTS', 'That date already is a staff holiday.');
            }
            $holiday = StaffHoliday::query()->forceCreate([
                'school_id' => $school->id, 'holiday_on' => $date, 'portion' => $portion->value, 'name' => trim($name),
                'created_by_user_id' => $actor->id, 'status' => StaffHoliday::ACTIVE, 'recorded_reason_code' => $past ? $reason?->value : null, 'recorded_at' => now(),
            ]);
            $this->audit->school($school, 'leave.calendar.holiday_added', actor: $actor, subject: $holiday, metadata: [
                'date' => $date, 'portion' => $portion->value, 'pastDated' => $past, 'reasonCode' => $past ? $reason?->value : null, ...($past ? ['mfa' => 'fresh'] : []),
            ]);

            return $holiday;
        }));
    }

    /** A holiday is withdrawn, never deleted (S-16). One that has taken effect is withdrawn only as a correction. */
    public function withdrawHoliday(School $school, string $holidayId, User $actor, ?CalendarChangeReason $reason = null, ?FreshMfaProof $mfa = null): StaffHoliday
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $holidayId, $actor, $reason, $mfa) {
            $this->guard->requireOperational($school->id);
            $holiday = StaffHoliday::query()->where('school_id', $school->id)->findOrFail($holidayId);
            $date = $holiday->holiday_on->toDateString();
            $effective = $date <= $this->history->today($school);
            if ($effective) {
                $this->requireCorrection($actor, $reason, $mfa);
                $this->gate->holdOpenMonths($school, $date, $date);
            }
            LeaveLocks::calendar($school, shared: false);
            if ($effective) {
                $this->authority->requireCurrent($actor, 'hr.leave.configure', $school);
            }
            $holiday = StaffHoliday::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($holidayId);
            if ($holiday->status !== StaffHoliday::ACTIVE) {
                throw LeaveException::conflict('LEAVE_HOLIDAY_WITHDRAWN', 'That holiday is already withdrawn.');
            }
            $start = $this->history->historyStart($school);
            if ($start !== null && $date < $start) {
                throw LeaveException::conflict('LEAVE_CALENDAR_BEFORE_HISTORY', "The staff calendar starts on {$start}; nothing before it changes.");
            }
            $holiday->forceFill([
                'status' => StaffHoliday::WITHDRAWN, 'withdrawn_by_user_id' => $actor->id, 'withdrawn_at' => now(),
                'withdrawn_reason_code' => $effective ? $reason?->value : null,
            ])->save();
            $this->audit->school($school, 'leave.calendar.holiday_withdrawn', actor: $actor, subject: $holiday, metadata: [
                'date' => $date, 'portion' => $holiday->portion, 'pastDated' => $effective, 'reasonCode' => $effective ? $reason?->value : null, ...($effective ? ['mfa' => 'fresh'] : []),
            ]);

            return $holiday;
        }));
    }

    /** @return array<int, string> ISO weekday => portion of the week in force today; empty while none is */
    public function pattern(School $school): array
    {
        $day = $this->history->day($school, $this->history->today($school));
        if (! $day->recorded()) {
            return [];
        }

        return $this->context->withSchool($school, fn () => StaffWorkingWeekVersion::query()->where('school_id', $school->id)->findOrFail($day->versionId)->pattern());
    }

    /** The deterministic working-day calculator for [from, to], on the calendar in force on each date. */
    public function calculator(School $school, string $from, string $to): WorkingDayCalculator
    {
        return $this->history->calculator($school, $from, $to);
    }

    /**
     * D6-4 (D14-08): what a saved change touches in [from, to], per owner. Counts only; nothing is
     * recalculated. Owners register through `CalendarChangeImpactSource::TAG`.
     *
     * @return list<array{label: string, count: int}>
     */
    public function impact(School $school, string $from, string $to, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        $impact = [];
        foreach (app()->tagged(CalendarChangeImpactSource::TAG) as $source) {
            /** @var CalendarChangeImpactSource $source */
            $impact[] = $source->impact($school, $from, $to);
        }

        return $impact;
    }

    /**
     * D6-3 (no backfill): a past date needs a history start at or before it. A School without history starts
     * today or later; today and later are never "before the history start", so a School is never stuck behind
     * a future first version.
     */
    private function refuseBeforeHistory(School $school, string $date, bool $past): void
    {
        if (! $past) {
            return;
        }
        $start = $this->history->historyStart($school);
        if ($start === null || $date < $start) {
            throw LeaveException::conflict('LEAVE_CALENDAR_BEFORE_HISTORY', $start === null
                ? 'The staff calendar has no history yet; it starts today or later, never on a past date.'
                : "The staff calendar starts on {$start}; nothing is recorded before it.");
        }
    }

    /** D6-5: a past-dated change needs a closed reason and the actor's own fresh MFA proof. */
    private function requireCorrection(User $actor, ?CalendarChangeReason $reason, ?FreshMfaProof $mfa): void
    {
        if ($reason === null) {
            throw LeaveException::invalid('LEAVE_CALENDAR_REASON_REQUIRED', 'A change to a past date is a correction: choose a reason.');
        }
        if ($mfa === null || ! $mfa->isFor($actor->id)) {
            throw new LogicException('A past-dated calendar correction needs the actor\'s own fresh MFA proof.');
        }
    }

    private static function requireDate(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw LeaveException::invalid('LEAVE_CALENDAR_DATE_INVALID', 'Give the date as YYYY-MM-DD.');
        }
    }
}
