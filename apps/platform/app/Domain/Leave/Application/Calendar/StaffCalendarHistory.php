<?php

namespace App\Domain\Leave\Application\Calendar;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\WorkingDayCalculator;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekVersion;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C22 (WO-5, D-6, `docs/hr/HR-R1-C21-D6-DECISION.md` §6.6): the one as-of reader of the staff
 * calendar. A date's answer is the active week version with the greatest `effective_from` not after
 * it, plus that date's active holiday. Before the School's first version the answer is NOT_RECORDED
 * (never today's calendar, D6-3); a School with no version at all is NOT_CONFIGURED.
 *
 * Request-scoped: nothing is cached across requests. Two queries per range, whatever its length.
 */
final class StaffCalendarHistory
{
    public function __construct(private readonly TenantContext $context) {}

    /** The School's own date on the database clock: what "past" means for a calendar change (rule 141). */
    public function today(School $school): string
    {
        return (string) DB::selectOne('SELECT public.staff_calendar_local_today(?) AS d', [$school->id])->d;
    }

    /** The first date with a recorded week, or null while none is recorded. */
    public function historyStart(School $school): ?string
    {
        return $this->context->withSchool($school, fn () => StaffWorkingWeekVersion::query()->where('school_id', $school->id)
            ->whereIn('status', [StaffWorkingWeekVersion::ACTIVE, StaffWorkingWeekVersion::SUPERSEDED])->min('effective_from'));
    }

    public function day(School $school, string $date): CalendarDay
    {
        return $this->range($school, $date, $date)[$date];
    }

    /** @return array<string, CalendarDay> date => answer, every date of [from, to] */
    public function range(School $school, string $from, string $to): array
    {
        [$versions, $holidays, $start] = $this->context->withSchool($school, function () use ($school, $from, $to) {
            $versions = StaffWorkingWeekVersion::query()->where('school_id', $school->id)->where('status', StaffWorkingWeekVersion::ACTIVE)
                ->where('effective_from', '<=', $to)->orderByDesc('effective_from')->get();
            $holidays = StaffHoliday::query()->where('school_id', $school->id)->where('status', StaffHoliday::ACTIVE)
                ->whereBetween('holiday_on', [$from, $to])->get()->keyBy(fn (StaffHoliday $h) => $h->holiday_on->toDateString());

            return [$versions, $holidays, $this->historyStart($school)];
        });

        $days = [];
        for ($d = CarbonImmutable::createFromFormat('!Y-m-d', $from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            if ($start === null) {
                $days[$date] = new CalendarDay($date, CalendarDay::NOT_CONFIGURED);

                continue;
            }
            $version = $versions->first(fn (StaffWorkingWeekVersion $v) => $v->effective_from->toDateString() <= $date);
            if ($date < $start || $version === null) {
                $days[$date] = new CalendarDay($date, CalendarDay::NOT_RECORDED);

                continue;
            }
            $holiday = $holidays->get($date);
            $days[$date] = new CalendarDay(
                $date,
                CalendarDay::RECORDED,
                $version->pattern()[$d->dayOfWeekIso],
                $holiday === null ? null : ['id' => $holiday->id, 'name' => $holiday->name, 'portion' => $holiday->portion],
                $version->id,
            );
        }

        return $days;
    }

    /**
     * The deterministic working-day calculator for [from, to], on the calendar as it stood on each
     * date (the formula is unchanged, ADR 0065 §22). Refuses while nothing is recorded, and for any
     * date before the history start: a count is never made from an unknown day.
     */
    public function calculator(School $school, string $from, string $to): WorkingDayCalculator
    {
        $days = $this->range($school, $from, $to);
        $patterns = [];
        $holidays = [];
        foreach ($days as $date => $day) {
            if ($day->status === CalendarDay::NOT_CONFIGURED) {
                throw LeaveException::conflict('LEAVE_CALENDAR_NOT_CONFIGURED', 'Configure the staff working week before counting leave days.');
            }
            if (! $day->recorded()) {
                throw LeaveException::conflict('LEAVE_CALENDAR_NOT_RECORDED', "The staff calendar was not recorded for {$date}: it starts on {$this->historyStart($school)}.");
            }
            $patterns[$date] = $day->weekPortion;
            if ($day->holiday !== null) {
                $holidays[$date] = $day->holiday['portion'];
            }
        }

        return WorkingDayCalculator::forDays($patterns, $holidays);
    }
}
