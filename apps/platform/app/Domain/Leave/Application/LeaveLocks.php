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
 * 00. eligibility (HR.9) or accrual month (HR.10; a run's start and each batch);
 *     then for an accrual batch: the run row, HR rows (FOR SHARE), assignment
 *     rows (FOR SHARE), year (shared), balance keys (sorted);
 * 00. eligibility (HR.9; one leave type's rule set, exclusive, right after the School -- policy
 *     create/supersede/retire and the eligibility run; before any other row lock);
 * 0a. staff employment (HRX.5, shared for HRX writers; exclusive only for a
 *     Payroll evidence capture);
 * 0b. staff days (HRX.3, the cross-domain day lock, ascending);
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

    /**
     * HRX.5 (ADR 0065 §26.9): one EmploymentRecord's HRX evidence as a whole.
     * Every HRX writer (leave approval and cancellation; attendance record,
     * register and correction) takes it SHARED, after its HR rows and before
     * the staff days; Payroll's evidence capture
     * (`PayrollAbsenceEvidenceReader::captureForPayroll()`) takes it
     * EXCLUSIVELY for each employment of a run, sorted by id, so a payroll
     * calculation sees each employment's evidence wholly before or wholly
     * after a concurrent write -- never a mix.
     */
    public static function staffEmployment(School $school, string $employmentRecordId, bool $shared): void
    {
        self::take("hrx.staff_employment:{$school->id}:{$employmentRecordId}", $shared);
    }

    /**
     * HR.9 (ADR 0065 §28.5): one leave type's eligibility rule set. Taken
     * exclusively FIRST (before any Leave or HR row lock) by every writer that
     * can change which active version an employment resolves to -- creating,
     * superseding or retiring a policy version -- and by the eligibility run,
     * so a run resolves against one stable rule set. The database rule guard
     * (`trg_leave_eligibility_rules_guard`) takes the same key.
     */
    public static function eligibility(School $school, string $leaveTypeId): void
    {
        self::take("leave.eligibility:{$school->id}:{$leaveTypeId}", shared: false);
    }

    /**
     * HR.10 (ADR 0065 §29.6): one month's accrual for one leave type and year.
     * Taken exclusively right after the School by a run's start and by each of
     * its batches, so two runs (or two workers of one run) never interleave
     * inside a month; the per-account unique key is the database backstop.
     */
    public static function accrual(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart): void
    {
        self::take("leave.accrual:{$school->id}:{$leaveTypeId}:{$leaveYearId}:{$periodStart}", shared: false);
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
