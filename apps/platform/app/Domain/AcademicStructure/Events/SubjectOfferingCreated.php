<?php

namespace App\Domain\AcademicStructure\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class SubjectOfferingCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $subjectOfferingId,
        public readonly string $academicYearId,
        public readonly string $gradeLevelId,
        public readonly string $subjectId,
    ) {}

    public function eventType(): string
    {
        return 'subject_offering.created.v1';
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
            'subjectOfferingId' => $this->subjectOfferingId,
            'academicYearId' => $this->academicYearId,
            'gradeLevelId' => $this->gradeLevelId,
            'subjectId' => $this->subjectId,
        ];
    }
}
