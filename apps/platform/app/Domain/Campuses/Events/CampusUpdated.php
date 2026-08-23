<?php

namespace App\Domain\Campuses\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class CampusUpdated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $campusId,
        public readonly array $changedFields,
    ) {}

    public function eventType(): string
    {
        return 'campus.updated.v1';
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
        return ['campusId' => $this->campusId, 'changedFields' => $this->changedFields];
    }
}
