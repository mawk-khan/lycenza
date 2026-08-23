<?php

namespace App\Domain\Campuses\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class CampusCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $campusId,
        public readonly string $name,
        public readonly string $code,
    ) {}

    public function eventType(): string
    {
        return 'campus.created.v1';
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
        return ['campusId' => $this->campusId, 'name' => $this->name, 'code' => $this->code];
    }
}
