<?php

namespace App\Domain\CurriculumDelivery\Application\Coverage;

/**
 * The complete aggregate result of
 * CurriculumCoverageReadService::coverageForAcademicYear(): unit counts
 * only, no entity collection, no delivery dates, no person identifier.
 */
final readonly class CurriculumCoverageCounts
{
    /**
     * @param  list<OfferingCoverageCounts>  $offerings
     */
    public function __construct(
        public string $academicYearId,
        public array $offerings,
    ) {}
}
