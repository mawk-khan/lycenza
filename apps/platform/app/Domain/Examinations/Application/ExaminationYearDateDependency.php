<?php

namespace App\Domain\Examinations\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Models\School;

/**
 * SIS 5.1 (MC-3): an examination lies inside its year (ExaminationService,
 * which share-locks the year); its papers lie inside the examination, so they
 * are covered through it.
 */
final class ExaminationYearDateDependency implements AcademicYearDateDependency
{
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int
    {
        return Examination::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where(fn ($q) => $q->where('starts_on', '<', $startsOn)->orWhere('ends_on', '>', $endsOn))
            ->count();
    }

    public function kind(): string
    {
        return 'examinations';
    }
}
