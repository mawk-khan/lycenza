<?php

namespace App\Domain\AcademicStructure\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class SubjectCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $subjectId,
        public readonly string $name,
        public readonly string $code,
    ) {}

    public function eventType(): string
    {
        return 'subject.created.v1';
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
        return ['subjectId' => $this->subjectId, 'name' => $this->name, 'code' => $this->code];
    }
}
