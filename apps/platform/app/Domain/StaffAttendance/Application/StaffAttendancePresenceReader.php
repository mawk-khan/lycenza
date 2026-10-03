<?php

namespace App\Domain\StaffAttendance\Application;

use App\Domain\Leave\Application\AttendancePresenceConflictReader;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * HRX.3 (ADR 0065 §24.5): Staff Attendance's implementation of Leave's
 * approval port. It reports recorded presence only -- `absent` never blocks
 * an approval. Bound in AppServiceProvider (the composition root), so Leave
 * never names this class. Read-only: Leave never writes attendance.
 */
class StaffAttendancePresenceReader implements AttendancePresenceConflictReader
{
    public function __construct(private readonly TenantContext $context) {}

    public function presentHalves(School $school, string $employmentRecordId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $dates): array {
            $present = [];
            StaffAttendanceRecord::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
                ->whereIn('attendance_date', $dates)->get()
                ->each(function (StaffAttendanceRecord $record) use (&$present): void {
                    $halves = array_keys(array_filter($record->halves(), fn (?string $status) => $status === 'present'));
                    if ($halves !== []) {
                        $present[$record->attendance_date->toDateString()] = $halves;
                    }
                });

            return $present;
        });
    }
}
