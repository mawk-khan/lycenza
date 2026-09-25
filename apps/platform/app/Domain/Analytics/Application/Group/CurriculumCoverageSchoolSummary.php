<?php

namespace App\Domain\Analytics\Application\Group;

use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;

/**
 * Phase 0N.11 (ADR 0048 section 7): one School's Group-safe curriculum
 * coverage -- its ACTIVE academic year only (no fallback), that year's
 * display name and code, and the School-level unit and Offering counts.
 * No ids, no grade-level, subject, campus or Section names, no person.
 */
final readonly class CurriculumCoverageSchoolSummary implements GroupSafeSchoolSummary
{
    public function __construct(
        public bool $hasActiveAcademicYear,
        public ?string $academicYearName,
        public ?string $academicYearCode,
        public int $planned,
        public int $completed,
        public int $inProgress,
        public int $notStarted,
        public int $offerings,
        public int $offeringsWithoutSyllabus,
    ) {}

    public static function noActiveAcademicYear(): self
    {
        return new self(false, null, null, 0, 0, 0, 0, 0, 0);
    }

    public function contributes(): bool
    {
        return $this->hasActiveAcademicYear;
    }

    public function coveragePercent(): ?string
    {
        return $this->hasActiveAcademicYear ? CurriculumCoverageReadModel::percent($this->completed, $this->planned) : null;
    }

    public function toArray(): array
    {
        if (! $this->hasActiveAcademicYear) {
            return ['hasActiveAcademicYear' => false];
        }

        return [
            'hasActiveAcademicYear' => true,
            'academicYearName' => $this->academicYearName,
            'academicYearCode' => $this->academicYearCode,
            'planned' => $this->planned,
            'completed' => $this->completed,
            'inProgress' => $this->inProgress,
            'notStarted' => $this->notStarted,
            'coveragePercent' => $this->coveragePercent(),
            'offerings' => $this->offerings,
            'offeringsWithoutSyllabus' => $this->offeringsWithoutSyllabus,
        ];
    }
}
