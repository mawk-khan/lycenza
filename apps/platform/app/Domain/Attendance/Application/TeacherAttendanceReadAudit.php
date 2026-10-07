<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;

/**
 * E33 / TCH-L1 (ADR 0063 section 43; determination section 5): the audit of
 * every successful owned teacher Attendance READ -- the session list, one
 * register, the owned classes of a date and a roster preview. Writes keep
 * their own events (`attendance.session.submitted`,
 * `attendance.record.corrected`).
 *
 * Called only after the read succeeded (a refused or not-found read records
 * nothing). Metadata is identifiers, the date and counts only: the acting
 * Employee, the School (the event's own column), the register / timetable
 * entry / Section / SubjectOffering context and the surface -- never a Student
 * id, name, roll number or status, so an audit row never copies the roster.
 */
class TeacherAttendanceReadAudit
{
    public const string SESSIONS_LISTED = 'attendance.teacher.sessions_listed';

    public const string SESSION_VIEWED = 'attendance.teacher.session_viewed';

    public const string CLASSES_LISTED = 'attendance.teacher.classes_listed';

    public const string ROSTER_VIEWED = 'attendance.teacher.roster_viewed';

    public const string SURFACE_WEB = 'web';

    public const string SURFACE_API = 'api';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function sessionsListed(School $school, User $actor, TeacherAttendanceScope $scope, ?string $attendanceDate, int $page, int $returned, string $surface): void
    {
        $this->audit->school($school, self::SESSIONS_LISTED, actor: $actor, metadata: [
            'actingEmployeeId' => $scope->employeeId,
            'attendanceDate' => $attendanceDate,
            'page' => $page,
            'resultCount' => $returned,
            'surface' => $surface,
        ]);
    }

    public function sessionViewed(School $school, User $actor, TeacherAttendanceScope $scope, AttendanceSession $session, string $surface): void
    {
        $this->audit->school($school, self::SESSION_VIEWED, actor: $actor, subject: $session, metadata: [
            'actingEmployeeId' => $scope->employeeId,
            'attendanceSessionId' => $session->id,
            'sectionId' => $session->section_id,
            'subjectOfferingId' => $session->subject_offering_id,
            'attendanceDate' => $session->attendance_date->toDateString(),
            'recordCount' => $session->relationLoaded('records') ? $session->records->count() : null,
            'surface' => $surface,
        ]);
    }

    public function classesListed(School $school, User $actor, TeacherAttendanceScope $scope, string $attendanceDate, int $classCount, string $surface): void
    {
        $this->audit->school($school, self::CLASSES_LISTED, actor: $actor, metadata: [
            'actingEmployeeId' => $scope->employeeId,
            'attendanceDate' => $attendanceDate,
            'classCount' => $classCount,
            'surface' => $surface,
        ]);
    }

    public function rosterViewed(School $school, User $actor, TeacherAttendanceScope $scope, string $timetableEntryId, string $sectionId, string $subjectOfferingId, string $attendanceDate, int $memberCount, string $surface): void
    {
        $this->audit->school($school, self::ROSTER_VIEWED, actor: $actor, metadata: [
            'actingEmployeeId' => $scope->employeeId,
            'timetableEntryId' => $timetableEntryId,
            'sectionId' => $sectionId,
            'subjectOfferingId' => $subjectOfferingId,
            'attendanceDate' => $attendanceDate,
            'memberCount' => $memberCount,
            'surface' => $surface,
        ]);
    }
}
