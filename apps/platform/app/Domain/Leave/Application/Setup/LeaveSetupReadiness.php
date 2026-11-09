<?php

namespace App\Domain\Leave\Application\Setup;

use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Domain\Leave\Infrastructure\StaffWorkingWeekVersion;
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
                // HR.R1-C22: a week in force today, not only one scheduled for later.
                'working_week' => StaffWorkingWeekVersion::query()->where('school_id', $school->id)->where('status', StaffWorkingWeekVersion::ACTIVE)->where('effective_from', '<=', $today)->exists(),
            ],
            configured: 'A leave year covers today, and leave types, policies and the staff working week are set up.',
            incomplete: 'Set up a leave year covering today, leave types and policies, and the staff working week.',
        );
    }
}
