<?php

namespace App\Domain\Communications\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 5A.4 §32. Not registered in App\Support\Webhooks\WebhookEventRegistry
 * -- internal-only, same as every other Announcement event (root
 * CLAUDE.md rule 45).
 */
class CommunicationAnnouncementScheduled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $announcementId,
        public readonly string $scheduledByUserId,
        public readonly string $scheduledAt,
    ) {}

    public function eventType(): string
    {
        return 'communication.announcement_scheduled.v1';
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
            'announcementId' => $this->announcementId,
            'scheduledByUserId' => $this->scheduledByUserId,
            'scheduledAt' => $this->scheduledAt,
        ];
    }
}
