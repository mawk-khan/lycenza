<?php

namespace App\Domain\StaffAttendance\Application;

/**
 * HRX.3 (ADR 0065 §12, §24.12): the Staff Attendance capabilities. Never a
 * role-name check. They are independent: neither implies the other, and
 * neither implies any Leave or Payroll capability.
 */
final class StaffAttendanceCapabilities
{
    /** Read the School's staff attendance: daily register, history, corrections. */
    public const VIEW = 'hr.staff_attendance.view';

    /** Record (single or bulk daily register) and correct staff attendance. */
    public const MANAGE = 'hr.staff_attendance.manage';

    /**
     * HR.R1-C18 (S-6): lock a School-local calendar month, which is never reopened, and
     * approve a locked-month correction someone else requested. Whole-School only.
     */
    public const LOCK = 'hr.staff_attendance.lock';

    /**
     * HRX.4: READ the acting Employee's own attendance, always together with
     * ActingEmployee ownership (ADR 0065 §25.6). Never a write.
     */
    public const SELF = 'hr.staff_attendance.self';
}
