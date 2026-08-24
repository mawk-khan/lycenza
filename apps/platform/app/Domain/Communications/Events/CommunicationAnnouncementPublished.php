<?php

namespace App\Domain\Communications\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Covers both "announcement.published" and "announcement.audience_resolved"
 * from brief §23 -- resolution and publication happen atomically in the
 * same transaction (AnnouncementService::publish()), so a separate
 * audience_resolved event with its own timestamp would be redundant;
 * `resolvedCount`/`audienceType` here already carry that fact. Payload
 * deliberately carries no message body (root CLAUDE.md rule 44/55).
 */
class CommunicationAnnouncementPublished implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $announcementId,
        public readonly string $messageId,
        public readonly string $audienceType,
        public readonly int $resolvedCount,
        public readonly string $publishedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'communication.announcement_published.v1';
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
            'messageId' => $this->messageId,
            'audienceType' => $this->audienceType,
            'resolvedCount' => $this->resolvedCount,
            'publishedByUserId' => $this->publishedByUserId,
        ];
    }
}
