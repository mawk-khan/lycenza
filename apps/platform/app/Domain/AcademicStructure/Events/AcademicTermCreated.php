<?php

namespace App\Domain\AcademicStructure\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class AcademicTermCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $academicYearId,
        public readonly string $academicTermId,
        public readonly string $name,
    ) {}

    public function eventType(): string
    {
        return 'academic_term.created.v1';
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
            'academicYearId' => $this->academicYearId,
            'academicTermId' => $this->academicTermId,
            'name' => $this->name,
        ];
    }
}
