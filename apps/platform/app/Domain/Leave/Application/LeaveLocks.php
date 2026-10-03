<?php

namespace App\Domain\Leave\Application;

use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HRX.2 (ADR 0065 §23.10): the Leave advisory transaction locks, keyed with
 * the School (rule 60), in ONE place. These are the same keys the database
 * triggers take.
 *
 * Every writer acquires them in this order:
 * 0. staff days (HRX.3, the cross-domain day lock, ascending);
 * 1. schedule;
 * 2. calendar;
 * 3. assignment;
 * 4. year (in leave-year order);
 * 5. balance (sorted).
 *
 * A writer that does not need a lock skips it, never reorders.
 */
final class LeaveLocks
{
    /**
     * HRX.3 (ADR 0065 §24.6): THE cross-domain lock serializing leave approval
     * and cancellation against Staff Attendance writes for one School x
     * EmploymentRecord x date. Leave owns the key; StaffAttendance takes it
     * through this method (StaffAttendance -> Leave is the permitted
     * direction). Dates are always taken ascending; a writer spanning several
     * employments takes them by employment id, then date.
     *
     * @param  list<string>  $dates  School-local Y-m-d
     */
    public static function staffDays(School $school, string $employmentRecordId, array $dates): void
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        foreach ($dates as $date) {
            self::take("hrx.staff_day:{$school->id}:{$employmentRecordId}:{$date}", shared: false);
        }
    }

    /** Exclusive for schedule writers (opening years, start-month changes); shared for readers that must see one schedule. */
    public static function schedule(School $school, bool $shared = false): void
    {
        self::take("leave.years:{$school->id}", $shared);
    }

    public static function calendar(School $school, bool $shared): void
    {
        self::take("leave.calendar:{$school->id}", $shared);
    }

    public static function assignment(School $school, string $employmentRecordId, string $leaveTypeId): void
    {
        self::take("leave.assignment:{$school->id}:{$employmentRecordId}:{$leaveTypeId}", shared: false);
    }

    /** Shared for every ledger writer and request; exclusive only for the year close. */
    public static function year(School $school, string $leaveYearId, bool $shared = true): void
    {
        self::take("leave.year:{$school->id}:{$leaveYearId}", $shared);
    }

    public static function balance(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId): void
    {
        self::take("leave.balance:{$school->id}:{$employmentRecordId}:{$leaveTypeId}:{$leaveYearId}", shared: false);
    }

    private static function take(string $key, bool $shared): void
    {
        DB::select($shared ? 'SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))' : 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
