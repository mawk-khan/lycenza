<?php

namespace App\Domain\StaffAttendance\Application\Locking;

use App\Domain\Leave\Application\Calendar\PastCalendarChangeGate;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Models\School;
use Carbon\CarbonImmutable;

/**
 * HR.R1-C22 (WO-5, D6-2, rule 147): Staff Attendance's answer to Leave's past-dated calendar change.
 * Each month the change reaches is taken shared, in month order (the same key and mode as every
 * Staff Attendance writer), so a change and a month lock serialize; a locked month refuses the
 * change. `trg_staff_attendance_calendar_month_guard` is the database's own copy of this rule.
 */
final class StaffAttendanceCalendarGate implements PastCalendarChangeGate
{
    public function holdOpenMonths(School $school, string $from, string $to): void
    {
        if ($from > $to) {
            return;
        }
        for ($m = CarbonImmutable::parse(StaffAttendanceMonthLocks::monthOf($from)); $m->toDateString() <= $to; $m = $m->addMonthNoOverflow()) {
            StaffAttendanceMonthLocks::take($school, $m->toDateString(), shared: true);
            if (StaffAttendanceMonthLocks::isLocked($school, $m->toDateString())) {
                throw LeaveException::conflict('LEAVE_CALENDAR_MONTH_LOCKED', "Staff attendance for {$m->format('Y-m')} is locked, so its calendar does not change.");
            }
        }
    }
}
