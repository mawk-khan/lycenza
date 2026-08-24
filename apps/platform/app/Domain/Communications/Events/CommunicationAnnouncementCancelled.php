<?php

namespace App\Domain\Communications\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

class CommunicationAnnouncementCancelled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $announcementId,
        public readonly string $cancelledByUserId,
    ) {}

    public function eventType(): string
    {
        return 'communication.announcement_cancelled.v1';
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
            'cancelledByUserId' => $this->cancelledByUserId,
        ];
    }
}
