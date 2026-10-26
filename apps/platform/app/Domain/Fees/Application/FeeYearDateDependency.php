<?php

namespace App\Domain\Fees\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearDateDependency;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\School;

/**
 * SIS 5.1 (MC-3): a fee instalment's period and a standing concession's window
 * lie inside their year (`fees_reject_non_draft_structure_child_write`,
 * `fees_validate_fee_concession`; both are immutable once in force, so they
 * could never be corrected afterwards). Their writers share-lock the year.
 */
final class FeeYearDateDependency implements AcademicYearDateDependency
{
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int
    {
        $lines = FeeStructureLine::query()->select('id')->where('school_id', $school->id)
            ->whereIn('fee_structure_id', FeeStructure::query()->select('id')->where('school_id', $school->id)->where('academic_year_id', $academicYearId));

        return FeeStructureInstallment::query()
            ->where('school_id', $school->id)
            ->whereIn('fee_structure_line_id', $lines)
            ->where(fn ($q) => $q->where('period_starts_on', '<', $startsOn)->orWhere('period_ends_on', '>', $endsOn))
            ->count()
            + FeeConcession::query()
                ->where('school_id', $school->id)
                ->where('academic_year_id', $academicYearId)
                ->where('scope', 'standing')
                ->where(fn ($q) => $q->where('valid_from', '<', $startsOn)->orWhere('valid_to', '>', $endsOn))
                ->count();
    }

    public function kind(): string
    {
        return 'fee instalments or concessions';
    }
}
