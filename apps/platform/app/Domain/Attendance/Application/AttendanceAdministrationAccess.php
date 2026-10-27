<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-1 (ADR 0076 §11.7, §37): the ONE place the administrative (Tier 1)
 * Attendance surface reads or targets registers, records and the classes
 * they are taken for under the actor's CampusScope (`attendance.view` and
 * `attendance.manage` are Campus-adopted).
 *
 * Where the Campus comes from -- never a request filter, a Student, the
 * selected Campus or an enrollment lookup:
 * - a new register: the Campus of the current TimetableEntry it is taken
 *   for (the Offering's, FK-bound to its Section);
 * - a submitted register and its records: the Campus STORED on the
 *   register when it was written (`attendance_sessions.campus_id`, NOT
 *   NULL, composite-FK-bound to its Section and Offering; every record is
 *   bound to its register's Campus). History keeps that Campus even if
 *   the timetable entry later changes, and a current Campus grant never
 *   reaches another Campus's history.
 *
 * Out of scope is NOT FOUND (404), exactly like another School's register.
 * The roster is the Section's own (StudentEnrollmentRosterReadService), so
 * no Student Campus resolution is involved. Teacher (Tier 2) Attendance is
 * unchanged and not Campus-adopted (TeacherAttendanceAccess).
 */
final class AttendanceAdministrationAccess
{
    /** @return Builder<AttendanceSession> */
    public function sessions(CampusScope $scope): Builder
    {
        return $scope->restrict(AttendanceSession::query());
    }

    public function session(CampusScope $scope, string $id): AttendanceSession
    {
        $session = Str::isUuid($id) ? $this->sessions($scope)->find($id) : null;

        return $session ?? throw (new ModelNotFoundException)->setModel(AttendanceSession::class, [$id]);
    }

    /** @return Builder<AttendanceRecord> records joined or not, restricted by their own stored Campus */
    public function records(CampusScope $scope, string $column = 'attendance_records.campus_id'): Builder
    {
        return $scope->restrict(AttendanceRecord::query(), $column);
    }

    /** @return Builder<TimetableEntry> the classes a register may be taken for */
    public function entries(CampusScope $scope): Builder
    {
        return $scope->restrict(TimetableEntry::query());
    }

    public function entry(CampusScope $scope, string $id): TimetableEntry
    {
        $entry = Str::isUuid($id) ? $this->entries($scope)->find($id) : null;

        return $entry ?? throw (new ModelNotFoundException)->setModel(TimetableEntry::class, [$id]);
    }

    /**
     * The in-scope classes that already have a register on $date -- judged
     * by the class, not the register's own Campus, so a class moved onto an
     * in-scope Campus still shows its earlier register as taken (and says
     * nothing about any other class).
     *
     * @return list<string>
     */
    /**
     * SIS 6.1: the submitted register of each in-scope class on $date, keyed
     * by timetable entry id (for linking a Registers row to its register).
     *
     * @return array<string, string>
     */
    public function submittedSessionIds(CampusScope $scope, string $date): array
    {
        return AttendanceSession::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('timetable_entry_id', $this->entries($scope)->select('id'))
            ->pluck('id', 'timetable_entry_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function submittedEntryIds(CampusScope $scope, string $date): array
    {
        return AttendanceSession::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('timetable_entry_id', $this->entries($scope)->select('id'))
            ->pluck('timetable_entry_id')
            ->all();
    }

    /**
     * The Campus check the Attendance write services run before their own
     * locks (the AttendanceWriteGuard hook, re-run when the entry is
     * repointed under its lock). None for a whole-School actor, so that path
     * is unchanged.
     */
    public function writeGuard(CampusScope $scope): ?AttendanceWriteGuard
    {
        return $scope->isWholeSchool() ? null : new CampusAttendanceWriteGuard($scope);
    }
}
