<?php

namespace App\Domain\Attendance\Application;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * TCH.4: what one verified acting teacher may SEE of Attendance -- the
 * registers whose exact Section + SubjectOffering their TeachingAssignments
 * cover ON the register's `attendance_date` (inclusive). Not the timetable
 * teacher, not the session's `teacher_id` snapshot, not today: the one
 * canonical Attendance date. Filtering happens in the query; an unowned
 * register never leaves the server.
 */
final readonly class TeacherAttendanceScope
{
    /**
     * @param  list<OwnedTeachingPeriod>  $periods
     */
    public function __construct(
        public string $employeeId,
        public string $asOf,
        public array $periods,
    ) {}

    public function ownsContext(string $sectionId, string $subjectOfferingId): bool
    {
        foreach ($this->periods as $period) {
            if ($period->sectionId === $sectionId && $period->subjectOfferingId === $subjectOfferingId) {
                return true;
            }
        }

        return false;
    }

    public function ownsOn(string $sectionId, string $subjectOfferingId, string $date): bool
    {
        foreach ($this->periods as $period) {
            if ($period->sectionId === $sectionId && $period->subjectOfferingId === $subjectOfferingId && $period->covers($date)) {
                return true;
            }
        }

        return false;
    }

    public function canSee(AttendanceSession $session): bool
    {
        return $this->ownsOn($session->section_id, $session->subject_offering_id, $session->attendance_date->toDateString());
    }

    /**
     * Restricts an AttendanceSession query to owned registers (an empty
     * scope matches nothing).
     *
     * @param  Builder<AttendanceSession>  $query
     * @return Builder<AttendanceSession>
     */
    public function constrain(Builder $query): Builder
    {
        if ($this->periods === []) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $any) {
            foreach ($this->periods as $period) {
                $any->orWhere(function (Builder $q) use ($period) {
                    $q->where('attendance_sessions.section_id', $period->sectionId)
                        ->where('attendance_sessions.subject_offering_id', $period->subjectOfferingId)
                        ->where('attendance_sessions.attendance_date', '>=', $period->startsOn);

                    if ($period->endsOn !== null) {
                        $q->where('attendance_sessions.attendance_date', '<=', $period->endsOn);
                    }
                });
            }
        });
    }
}
