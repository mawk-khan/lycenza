<?php

namespace App\Domain\Communications\Application;

use Illuminate\Support\Carbon;

/**
 * Phase 5A.7 §18/§21 -- one thread's read-state/latest-activity
 * summary, as produced by ConversationReadModel::summarize(). A named
 * value object (matching this module's existing convention --
 * CommunicationDeliveryResult, ResolvedAudience,
 * App\Support\Email\Providers\OutboundEmail) rather than a loose array shape.
 */
final class ConversationThreadSummary
{
    public function __construct(
        public readonly ?Carbon $lastReadAt,
        public readonly ?string $latestMessageId,
        public readonly ?string $latestMessageBody,
        public readonly ?string $latestMessageSenderId,
        public readonly ?Carbon $latestMessageAt,
        public readonly bool $hasAttachment,
        public readonly bool $unread,
    ) {}
}
