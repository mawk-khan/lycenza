<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\School;

/**
 * SIS 5.1 (MC-3): an Assignment's due date lies inside its Subject Offering's
 * year (AssignmentService, which share-locks the year). No due date, no rule.
 */
final class AssignmentYearDateDependency implements AcademicYearDateDependency
{
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int
    {
        return Assignment::query()
            ->where('school_id', $school->id)
            ->whereIn('subject_offering_id', SubjectOffering::query()->select('id')->where('school_id', $school->id)->where('academic_year_id', $academicYearId))
            ->whereNotNull('due_on')
            ->where(fn ($q) => $q->where('due_on', '<', $startsOn)->orWhere('due_on', '>', $endsOn))
            ->count();
    }

    public function kind(): string
    {
        return 'assignment due dates';
    }
}
