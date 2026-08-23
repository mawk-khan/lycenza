<?php

namespace App\Domain\AcademicStructure\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class SectionCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $sectionId,
        public readonly string $academicYearId,
        public readonly string $gradeLevelId,
        public readonly string $name,
    ) {}

    public function eventType(): string
    {
        return 'section.created.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'sectionId' => $this->sectionId,
            'academicYearId' => $this->academicYearId,
            'gradeLevelId' => $this->gradeLevelId,
            'name' => $this->name,
        ];
    }
}
