<?php

namespace App\Domain\CurriculumDelivery\Application\Coverage;

/**
 * One AcademicYear a coverage report can be requested for.
 */
final readonly class AcademicYearOption
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public bool $isActive,
    ) {}
}
