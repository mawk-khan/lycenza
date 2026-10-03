<?php

namespace Tests\Feature\StaffAttendance\Concerns;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Models\User;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;

/**
 * HRX.3: a Leave world (HRX.1/HRX.2 fixtures) with the staff working week
 * configured (Mon-Fri full, Saturday morning only, Sunday off), a balance to
 * approve leave against, and a Staff Attendance administrator.
 */
trait CreatesStaffAttendanceFixtures
{
    use CreatesLeaveFixtures;

    protected function attendanceAdmin(School $school): User
    {
        return $this->createUserWithCapabilities($school, ['hr.staff_attendance.view', 'hr.staff_attendance.manage']);
    }

    /** @return array<string, mixed> leaveWorld() + 'clerk' (Staff Attendance administrator) */
    protected function attendanceWorld(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w);
        $w['clerk'] = $this->attendanceAdmin($w['school']);

        return $w;
    }

    protected function recordAttendance(array $w, string $date, ?string $first, ?string $second, ?EmploymentRecord $employment = null): StaffAttendanceRecord
    {
        return app(StaffAttendanceService::class)->record($w['school'], ($employment ?? $w['employment'])->id, $date, $first, $second, $w['clerk']);
    }

    protected function correctAttendance(array $w, StaffAttendanceRecord $record, ?string $first, ?string $second, string $reason = 'entered_in_error', ?int $version = null): StaffAttendanceRecord
    {
        return app(StaffAttendanceService::class)->correct($w['school'], $record->id, $version ?? $record->version, $first, $second, $reason, $w['clerk']);
    }

    protected function approvedLeave(array $w, string $from, string $to, string $startPortion = 'full', ?string $endPortion = null): LeaveRequest
    {
        $request = $this->submitLeave($w, $from, $to, $startPortion, $endPortion);

        return app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);
    }
}
