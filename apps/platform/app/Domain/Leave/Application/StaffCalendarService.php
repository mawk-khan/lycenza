<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekday;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.7): the staff working calendar Leave owns -- a weekly
 * working pattern (each ISO weekday `full`, `first_half` or `off`) and
 * dated staff holidays. No School calendar exists elsewhere to reuse; this
 * one is staff-only and never touches an academic calendar, timetable or
 * Student attendance.
 *
 * Fail-closed: until all seven weekdays are configured, `calculator()`
 * refuses (no default working week is assumed). Leave units are fixed when
 * evidence is written, so a later calendar change never rewrites them.
 */
class StaffCalendarService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
    ) {}

    /** @param  array<int, string>  $pattern  ISO weekday 1..7 => full|first_half|off, all seven */
    public function setWeeklyPattern(School $school, array $pattern, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        ksort($pattern);
        if (array_keys($pattern) !== [1, 2, 3, 4, 5, 6, 7] || array_diff($pattern, StaffWorkingWeekday::PORTIONS) !== []) {
            throw LeaveException::invalid('LEAVE_CALENDAR_PATTERN_INVALID', 'Give every ISO weekday 1..7 exactly once, each full, first_half or off.');
        }

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $pattern, $actor) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::calendar($school, shared: false);
            $before = $this->pattern($school);
            foreach ($pattern as $weekday => $portion) {
                StaffWorkingWeekday::query()->updateOrCreate(
                    ['school_id' => $school->id, 'iso_weekday' => $weekday],
                    ['portion' => $portion, 'updated_by_user_id' => $actor->id],
                );
            }
            $this->audit->school($school, 'leave.calendar.pattern_changed', actor: $actor, metadata: ['before' => $before, 'after' => $pattern]);
        }));
    }

    public function addHoliday(School $school, string $date, DayPortion $portion, string $name, User $actor): StaffHoliday
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $portion, $name, $actor) {
            $this->guard->requireOperational($school->id);
            // Every calendar writer holds the calendar lock, so an approval reads one consistent calendar (ADR 0065 §23.10).
            LeaveLocks::calendar($school, shared: false);
            try {
                $holiday = StaffHoliday::query()->create(['school_id' => $school->id, 'holiday_on' => $date, 'portion' => $portion->value, 'name' => trim($name), 'created_by_user_id' => $actor->id]);
            } catch (UniqueConstraintViolationException) {
                throw LeaveException::conflict('LEAVE_HOLIDAY_EXISTS', 'That date already is a staff holiday.');
            }
            $this->audit->school($school, 'leave.calendar.holiday_added', actor: $actor, subject: $holiday, metadata: ['date' => $date, 'portion' => $portion->value]);

            return $holiday;
        }));
    }

    public function removeHoliday(School $school, string $holidayId, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $holidayId, $actor) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::calendar($school, shared: false);
            $holiday = StaffHoliday::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($holidayId);
            $holiday->delete();
            $this->audit->school($school, 'leave.calendar.holiday_removed', actor: $actor, metadata: ['date' => $holiday->holiday_on->toDateString(), 'portion' => $holiday->portion]);
        }));
    }

    /** @return array<int, string> ISO weekday => portion (only configured days) */
    public function pattern(School $school): array
    {
        return $this->context->withSchool($school, fn () => StaffWorkingWeekday::query()->where('school_id', $school->id)
            ->orderBy('iso_weekday')->pluck('portion', 'iso_weekday')->map(fn ($p) => (string) $p)->all());
    }

    /** The deterministic working-day calculator for [from, to]; refuses while the week is unconfigured. */
    public function calculator(School $school, string $from, string $to): WorkingDayCalculator
    {
        $pattern = $this->pattern($school);
        if (count($pattern) !== 7) {
            throw LeaveException::conflict('LEAVE_CALENDAR_NOT_CONFIGURED', 'Configure the staff working week before counting leave days.');
        }
        $holidays = $this->context->withSchool($school, fn () => StaffHoliday::query()->where('school_id', $school->id)
            ->whereBetween('holiday_on', [$from, $to])->get()
            ->mapWithKeys(fn (StaffHoliday $h) => [$h->holiday_on->toDateString() => $h->portion])->all());

        return new WorkingDayCalculator($pattern, $holidays);
    }
}
