<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;

/**
 * SIS 5.1 (MC-3): a required or elective teaching assignment starts inside its
 * year and never ends after it (TeachingAssignmentService,
 * ElectiveTeachingAssignmentService, which share-lock the year). An end before
 * the start (`employment_ended`) is not a year rule and is not counted.
 */
final class TeachingAssignmentYearDateDependency implements AcademicYearDateDependency
{
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int
    {
        $outside = fn ($q) => $q->where('starts_on', '<', $startsOn)->orWhere('starts_on', '>', $endsOn)->orWhere('ends_on', '>', $endsOn);

        return TeachingAssignment::query()->where('school_id', $school->id)->where('academic_year_id', $academicYearId)->where($outside)->count()
            + ElectiveTeachingAssignment::query()->where('school_id', $school->id)->where('academic_year_id', $academicYearId)->where($outside)->count();
    }

    public function kind(): string
    {
        return 'teaching assignments';
    }
}
