<?php

namespace App\Domain\CurriculumDelivery\Application\Coverage;

/**
 * Coverage counts for one required, active SubjectOffering: its number
 * of active SyllabusUnits and, per active Section sharing its
 * AcademicYear/Campus/GradeLevel context, how many of those units are
 * completed, in progress and not started.
 */
final readonly class OfferingCoverageCounts
{
    /**
     * @param  list<SectionCoverageCounts>  $sections
     */
    public function __construct(
        public string $subjectOfferingId,
        public string $subjectCode,
        public string $subjectName,
        public string $gradeLevelName,
        public int $gradeLevelSequence,
        public string $campusName,
        public int $activeSyllabusUnits,
        public array $sections,
    ) {}
}
