<?php

namespace App\Domain\Communications\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Not registered in App\Support\Webhooks\WebhookEventRegistry -- not
 * externally webhook-publishable in this checkpoint (root CLAUDE.md
 * rule 45; brief §24 excludes external delivery entirely).
 */
class CommunicationThreadCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $threadId,
        public readonly string $threadType,
        public readonly string $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'communication.thread_created.v1';
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
            'threadId' => $this->threadId,
            'threadType' => $this->threadType,
            'createdByUserId' => $this->createdByUserId,
        ];
    }
}
