<?php

namespace App\Domain\HR\Application\Setup;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Staff records readiness -- HR configuration and
 * at least one current employment. EXISTS answers only; never an Employee,
 * a name or a count of staff.
 */
class StaffRecordsSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['staff.staff_records'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::fromChecks(
            [
                'departments' => Department::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'positions' => Position::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'current_employment' => EmploymentRecord::query()->where('school_id', $school->id)
                    ->whereIn('status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)->exists(),
            ],
            configured: 'HR departments and positions exist and staff are employed.',
            incomplete: 'Set up HR departments and positions, and onboard staff.',
        );
    }
}
