<?php

namespace App\Support\Events;

/**
 * Convenience trait for concrete ShouldBeOutboxed events -- provides
 * sane defaults (no campus, no extra metadata, no causation) so a
 * simple event only needs to implement eventType()/eventVersion()/
 * schoolId()/payload().
 */
trait OutboxedEventDefaults
{
    protected ?string $causationId = null;

    public function campusId(): ?string
    {
        return null;
    }

    public function metadata(): array
    {
        return [];
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function causedBy(?string $eventId): static
    {
        $this->causationId = $eventId;

        return $this;
    }
}
