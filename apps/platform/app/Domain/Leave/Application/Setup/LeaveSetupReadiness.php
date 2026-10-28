<?php

namespace App\Domain\Leave\Application\Setup;

use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekday;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Tenancy\SchoolTimezone;

/**
 * ORG.12A (ADR 0080 §10): Leave configuration readiness over Leave's own
 * configuration tables (four EXISTS queries); "today" is School-local.
 */
class LeaveSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['staff.leave'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        $today = SchoolTimezone::today($school);

        return SetupReadiness::fromChecks(
            [
                'leave_year' => LeaveYear::query()->where('school_id', $school->id)
                    ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->exists(),
                'leave_types' => LeaveType::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'leave_policies' => LeavePolicy::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'working_week' => StaffWorkingWeekday::query()->where('school_id', $school->id)->exists(),
            ],
            configured: 'A leave year covers today, and leave types, policies and the staff working week are set up.',
            incomplete: 'Set up a leave year covering today, leave types and policies, and the staff working week.',
        );
    }
}
