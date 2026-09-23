<?php

namespace App\Domain\CurriculumDelivery\Application\Coverage;

/**
 * Syllabus-unit coverage counts for one Section of one required
 * SubjectOffering. Every number counts SYLLABUS UNITS -- never a
 * Student, Employee or any other person (a Section is an organisational
 * cohort, not an individual; docs/security/DATA-CLASSIFICATION.md,
 * "Curriculum delivery records").
 *
 * `notStarted` is computed, never stored: CurriculumDelivery has no
 * `not_started` row, so it is `plannedUnits - completed - inProgress`.
 */
final readonly class SectionCoverageCounts
{
    public function __construct(
        public string $sectionId,
        public string $sectionName,
        public string $sectionCode,
        public int $plannedUnits,
        public int $completedUnits,
        public int $inProgressUnits,
        public int $notStartedUnits,
    ) {}
}
