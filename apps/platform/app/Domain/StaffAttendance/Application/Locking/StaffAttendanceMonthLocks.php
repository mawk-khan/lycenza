<?php

namespace App\Domain\StaffAttendance\Application\Locking;

use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C18 (WO-6, S-6): the one advisory key of a School-month, `hr.staff_month:{school}:{YYYY-MM}`,
 * the same string `staff_attendance_month_key()` hashes in the database. Every write to a month's
 * records takes it SHARED, after the School and before its HR rows; a lock takes it EXCLUSIVELY.
 * The database triggers take it too, so a raw write serializes with locking as well.
 */
final class StaffAttendanceMonthLocks
{
    /** The School-local calendar month of a School-local date, as its first day. */
    public static function monthOf(string $date): string
    {
        return substr($date, 0, 7).'-01';
    }

    public static function take(School $school, string $month, bool $shared): void
    {
        $key = 'hr.staff_month:'.$school->id.':'.substr($month, 0, 7);
        DB::select($shared ? 'SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))' : 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    public static function isLocked(School $school, string $month): bool
    {
        return DB::table('staff_attendance_month_locks')->where('school_id', $school->id)->where('month', $month)->exists();
    }

    /** Takes the month SHARED and refuses when it is locked: the clean refusal the triggers back up. */
    public static function holdOpen(School $school, string $date): void
    {
        $month = self::monthOf($date);
        self::take($school, $month, shared: true);
        if (self::isLocked($school, $month)) {
            throw StaffAttendanceException::conflict('STAFF_ATTENDANCE_MONTH_LOCKED', 'This month is locked. A change needs a locked-month correction, approved by someone else.');
        }
    }
}
