<?php

namespace App\Domain\Platform\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0C's one demonstration domain event -- proves the full
 * transaction -> outbox -> dispatcher -> queue -> idempotent-consumer
 * chain (section 67) without implementing a business ERP module.
 * event_type: "school.setting.changed.v1" (see EVENTS.md's naming
 * convention). Payload carries the key/new value only -- never the
 * full School record.
 */
class SchoolSettingChanged implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $key,
        public readonly mixed $value,
    ) {}

    public function eventType(): string
    {
        return 'school.setting.changed.v1';
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
        return ['key' => $this->key, 'value' => $this->value];
    }
}
