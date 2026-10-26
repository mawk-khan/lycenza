<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;

/**
 * SIS 5.1 (MC-3): a placement starts inside its year (SIS 4.1), never ends
 * after it (`student_enrollments_end_within_year`), and a completed one ends
 * exactly on the year's last day. Placement writers share-lock the year
 * (StudentEnrollmentService::lockAcademicYearForPlacement()).
 */
final class PlacementYearDateDependency implements AcademicYearDateDependency
{
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int
    {
        return StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where(fn ($q) => $q->where('starts_on', '<', $startsOn)
                ->orWhere('starts_on', '>', $endsOn)
                ->orWhere('ends_on', '>', $endsOn)
                ->orWhere(fn ($q) => $q->where('status', 'completed')->where('ends_on', '!=', $endsOn)))
            ->count();
    }

    public function kind(): string
    {
        return 'placements';
    }
}
