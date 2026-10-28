<?php

namespace App\Domain\Attendance\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Student attendance has no configuration of its
 * own -- registers are taken against the timetable and current placements.
 * What it needs is expressed as setup DEPENDENCIES (Student records and
 * Timetable), which OrganizationSetupReadService resolves.
 */
class StudentAttendanceSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['attendance.student_attendance'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::noSetupRequired('Registers are taken against the timetable; no separate attendance setup is needed.');
    }
}
