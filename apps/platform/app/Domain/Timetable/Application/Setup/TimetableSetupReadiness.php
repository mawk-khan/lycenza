<?php

namespace App\Domain\Timetable\Application\Setup;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Timetable readiness -- active periods and an
 * active schedule entry in the active academic year (two EXISTS queries).
 */
class TimetableSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly CurrentAcademicYearResolver $years) {}

    public function keys(): array
    {
        return ['academic.timetable'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        $yearId = $this->years->tryResolve($school)?->id;

        return SetupReadiness::fromChecks(
            [
                'periods' => TimetablePeriod::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'schedule' => $yearId !== null && TimetableEntry::query()->where('school_id', $school->id)
                    ->where('academic_year_id', $yearId)->where('status', 'active')->exists(),
            ],
            configured: 'Timetable periods and a class schedule for the active year are set up.',
            incomplete: 'Define timetable periods, then schedule classes for the active year.',
        );
    }
}
