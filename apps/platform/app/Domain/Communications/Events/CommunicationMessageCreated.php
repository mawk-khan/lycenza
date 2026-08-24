<?php

namespace App\Domain\Communications\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Payload deliberately carries no message body (root CLAUDE.md rule
 * 44/55) -- references and facts only. A future consumer needing the
 * content reads the canonical CommunicationMessage row itself.
 */
class CommunicationMessageCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $threadId,
        public readonly string $messageId,
        public readonly string $senderUserId,
        public readonly string $priority,
        public readonly int $recipientCount,
    ) {}

    public function eventType(): string
    {
        return 'communication.message_created.v1';
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
            'messageId' => $this->messageId,
            'senderUserId' => $this->senderUserId,
            'priority' => $this->priority,
            'recipientCount' => $this->recipientCount,
        ];
    }
}
