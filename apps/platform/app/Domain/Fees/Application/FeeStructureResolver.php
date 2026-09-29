<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Models\School;

/**
 * FEE.1/FEE.2 (ADR 0062 §7.1): the one structure-resolution rule --
 * the ACTIVE campus-override structure for AcademicYear x GradeLevel x
 * Campus if one exists, otherwise the ACTIVE School-wide default (campus
 * NULL), otherwise none. Never Section-specific.
 *
 * Trusted and authorization-neutral: `FeeStructureReadService` calls it
 * after its own capability check, and the FEE.2 executor calls it inside
 * an item transaction. Must run inside the School's TenantContext.
 */
class FeeStructureResolver
{
    public function activeStructureFor(School $school, string $academicYearId, string $gradeLevelId, ?string $campusId): ?FeeStructure
    {
        $base = fn () => FeeStructure::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->where('status', FeeStructure::STATUS_ACTIVE);

        if ($campusId !== null) {
            $override = $base()->where('campus_id', $campusId)->first();
            if ($override !== null) {
                return $override;
            }
        }

        return $base()->whereNull('campus_id')->first();
    }

    /**
     * Campuses that have their own ACTIVE override for this year and grade,
     * and so are never billed by the School-wide default structure.
     *
     * @return list<string>
     */
    public function overrideCampusIds(School $school, string $academicYearId, string $gradeLevelId): array
    {
        return FeeStructure::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->where('status', FeeStructure::STATUS_ACTIVE)
            ->whereNotNull('campus_id')
            ->pluck('campus_id')
            ->values()
            ->all();
    }
}
