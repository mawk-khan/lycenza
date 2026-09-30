<?php

namespace App\Domain\Attendance\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * TCH.4 (ADR 0063 section 11, Tier 2) -- the owned-teacher authorization
 * path for Attendance, the second adopter of ADR 0063:
 *
 *   authenticated User (route) AND trusted School context (route)
 *   AND `attendance.teacher`                 -- capability, never a role key
 *   AND a verified ActingEmployee today      -- HR ActingEmployeeResolver
 *   AND a TeachingAssignment of that Employee for the exact Section +
 *       SubjectOffering covering the register's attendance_date
 *
 * Every part is required; any missing part fails closed.
 *
 * What does NOT authorize:
 * - a role name (any role carrying the capability works the same);
 * - `timetable_entries.teacher_id` -- the TimetableEntry only names the
 *   class being taken;
 * - `attendance_sessions.teacher_id` -- the scheduled teacher at submission
 *   (provenance), which legitimately differs from the acting teacher under
 *   temporary cover or co-teaching.
 *
 * The actor must be eligible TODAY; ownership is judged on the register's
 * `attendance_date` -- never today, created_at or submitted_at.
 *
 * Reads filter the same models; writes run AttendanceSubmissionService /
 * AttendanceCorrectionService with a TeacherAttendanceGuard. Production
 * enablement is gated by the TCH-L1 legal/compliance determination
 * (ADR 0063 section 26); nothing here decides it.
 */
class TeacherAttendanceAccess
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'attendance.teacher';

    public function __construct(
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    /** For reads: the capability, a fresh ActingEmployee, the ownership periods. Nothing cached. */
    public function scope(User $actor, School $school): TeacherAttendanceScope
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $acting = $this->identities->resolve($actor, $school);

        return new TeacherAttendanceScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    /** For writes: a guard to pass to the Attendance write services. */
    public function guard(User $actor): TeacherAttendanceGuard
    {
        return new TeacherAttendanceGuard($actor, $this->identities, $this->ownership);
    }
}
