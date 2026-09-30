<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Application\Exceptions\AttendanceOutsideTeachingAssignmentException;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.4: the Tier 2 write check, run by the Attendance write services
 * INSIDE their transaction (AttendanceWriteGuard). In order:
 *
 *   1. `attendance.teacher` (CapabilityResolver, whose cache the role
 *      grant/revoke path already clears);
 *   2. ActingEmployeeResolver::hold() for today -- School, membership,
 *      User, Employee, EmploymentRecord FOR SHARE (once per transaction);
 *   3. visibility: the class (and, for a correction, the register) must be
 *      the teacher's -- else the same not-found as a missing row (ADR 0063
 *      section 18);
 *   4. TeachingOwnership::hold() on the attendance_date -- the covering
 *      TeachingAssignment FOR SHARE, so ending it either commits first
 *      (and this refuses) or waits for the register write.
 *
 * Only then do the services take their own locks: School -> membership
 * -> User -> Employee -> EmploymentRecord -> TeachingAssignment ->
 * TimetableEntry -> AcademicYear -> Section -> StudentEnrollment rows ->
 * attendance writes (submission), or -> attendance_records (correction).
 */
final class TeacherAttendanceGuard implements AttendanceWriteGuard
{
    use AuthorizesCapability;

    private ?ActingEmployee $acting = null;

    public function __construct(
        private readonly User $actor,
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    public function beforeSubmit(School $school, string $sectionId, string $subjectOfferingId, string $attendanceDate): void
    {
        $acting = $this->holdActor($school);

        if (! $this->scope($school, $acting)->ownsContext($sectionId, $subjectOfferingId)) {
            throw (new ModelNotFoundException)->setModel(AttendanceSession::class);
        }

        $this->holdOwnership($school, $acting, $sectionId, $subjectOfferingId, $attendanceDate);
    }

    public function beforeCorrect(School $school, AttendanceSession $session): void
    {
        $acting = $this->holdActor($school);

        // A register the teacher does not own on its own date is invisible
        // (404) -- even in a class they own at other dates.
        if (! $this->scope($school, $acting)->canSee($session)) {
            throw (new ModelNotFoundException)->setModel(AttendanceSession::class, [$session->id]);
        }

        $this->holdOwnership($school, $acting, $session->section_id, $session->subject_offering_id, $session->attendance_date->toDateString());
    }

    private function holdActor(School $school): ActingEmployee
    {
        $this->authorizeCapabilityFor($this->actor, TeacherAttendanceAccess::CAPABILITY, $school);

        return $this->acting ??= $this->identities->hold($this->actor, $school);
    }

    private function scope(School $school, ActingEmployee $acting): TeacherAttendanceScope
    {
        return new TeacherAttendanceScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    private function holdOwnership(School $school, ActingEmployee $acting, string $sectionId, string $subjectOfferingId, string $date): void
    {
        if (! $this->ownership->hold($school, $acting->employeeId, $sectionId, $subjectOfferingId, $date)) {
            throw new AttendanceOutsideTeachingAssignmentException;
        }
    }
}
