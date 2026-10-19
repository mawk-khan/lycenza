<?php

namespace App\Domain\Attendance\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7D-1 (ADR 0076 §37): the Tier 1 Campus check of a Campus-scoped
 * Attendance administrator, run by AttendanceSubmissionService /
 * AttendanceCorrectionService through the AttendanceWriteGuard hook --
 * before their locks, and (submission) again if the timetable entry was
 * repointed at another class under its lock.
 *
 * - Submit: the class's Section must be on a Campus in scope (the Section
 *   and the entry share the Offering's Campus by composite foreign key).
 * - Correct: the register's STORED Campus must be in scope -- the Campus at
 *   the time of writing, never a current one.
 *
 * Out of scope is NOT FOUND; the services answer it exactly like an
 * unknown entry or record. Built only by AttendanceAdministrationAccess,
 * from a CampusScope the resolver computed.
 */
final class CampusAttendanceWriteGuard implements AttendanceWriteGuard
{
    public function __construct(private readonly CampusScope $scope) {}

    public function beforeSubmit(School $school, string $sectionId, string $subjectOfferingId, string $attendanceDate): void
    {
        $campusId = Section::query()->where('school_id', $school->id)->whereKey($sectionId)->value('campus_id');

        if (! $this->scope->covers(is_string($campusId) ? $campusId : null)) {
            throw (new ModelNotFoundException)->setModel(AttendanceSession::class);
        }
    }

    public function beforeCorrect(School $school, AttendanceSession $session): void
    {
        if (! $this->scope->covers($session->campus_id)) {
            throw (new ModelNotFoundException)->setModel(AttendanceSession::class);
        }
    }
}
