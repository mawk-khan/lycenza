<?php

namespace App\Domain\StaffAttendance\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * HRX.3 (ADR 0065 §16, §24.10): a Staff Attendance record was corrected.
 * Minimized: ids, the date, the version step, both halves before and after
 * and the closed reason code -- no leave detail, no free text, nothing
 * health-related. The signal HRX.5 will turn into a payroll pending
 * difference; nothing consumes it before then. Not webhook-publishable
 * (rules 45, 77).
 */
class StaffAttendanceCorrected implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /**
     * @param  array{firstHalf: ?string, secondHalf: ?string}  $before
     * @param  array{firstHalf: ?string, secondHalf: ?string}  $after
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $staffAttendanceRecordId,
        public readonly string $employmentRecordId,
        public readonly string $employeeId,
        public readonly string $attendanceDate,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $before,
        public readonly array $after,
        public readonly string $reasonCode,
    ) {}

    public function eventType(): string
    {
        return 'staff_attendance.corrected.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'staffAttendanceRecordId' => $this->staffAttendanceRecordId, 'employmentRecordId' => $this->employmentRecordId,
            'employeeId' => $this->employeeId, 'attendanceDate' => $this->attendanceDate,
            'fromVersion' => $this->fromVersion, 'toVersion' => $this->toVersion,
            'before' => $this->before, 'after' => $this->after, 'reasonCode' => $this->reasonCode,
        ];
    }
}
