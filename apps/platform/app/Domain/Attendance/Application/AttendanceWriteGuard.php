<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\School;

/**
 * TCH.4: an extra authorization step the Attendance write services run
 * INSIDE their own transaction, before they lock or write any Attendance
 * row. The Tier 1 path (School-wide `attendance.manage`) passes none; the
 * Tier 2 owned-teacher path passes TeacherAttendanceGuard. The register
 * rules, locks, roster discipline and compare-and-swap stay the services'
 * own -- there is no teacher copy of them.
 */
interface AttendanceWriteGuard
{
    /** Before a register is submitted: the class context the TimetableEntry names, and the date. */
    public function beforeSubmit(School $school, string $sectionId, string $subjectOfferingId, string $attendanceDate): void;

    /** Before a record of an already-submitted register is corrected. */
    public function beforeCorrect(School $school, AttendanceSession $session): void;
}
