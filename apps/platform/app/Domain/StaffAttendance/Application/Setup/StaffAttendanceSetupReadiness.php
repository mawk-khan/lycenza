<?php

namespace App\Domain\StaffAttendance\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Staff attendance has no configuration of its own
 * -- it uses the staff working week and holidays owned by Leave, expressed
 * as a setup DEPENDENCY (`staff.leave`).
 */
class StaffAttendanceSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['attendance.staff_attendance'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::noSetupRequired('Staff presence uses the working week and holidays from Leave; no separate setup is needed.');
    }
}
