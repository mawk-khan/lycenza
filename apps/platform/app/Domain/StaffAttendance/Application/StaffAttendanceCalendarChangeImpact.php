<?php

namespace App\Domain\StaffAttendance\Application;

use App\Domain\Leave\Application\Calendar\CalendarChangeImpactSource;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/** HR.R1-C22 (WO-5, D6-4): Staff Attendance's part of the calendar change notice: days already recorded in the range. */
final class StaffAttendanceCalendarChangeImpact implements CalendarChangeImpactSource
{
    public function __construct(private readonly TenantContext $context) {}

    public function impact(School $school, string $from, string $to): array
    {
        $count = $this->context->withSchool($school, fn () => StaffAttendanceRecord::query()->where('school_id', $school->id)
            ->whereBetween('attendance_date', [$from, $to])->count());

        return ['label' => 'staff attendance days already recorded', 'count' => $count];
    }
}
