<?php

namespace App\Domain\Communications\Application;

use Carbon\Carbon;

/**
 * Phase 5A.8 §11 -- one stable presentation shape for a mixed Inbox/
 * Unread/Sent/search result, regardless of which canonical domain
 * (Conversation, Announcement) produced it. A named value object
 * (matching this module's existing convention --
 * CommunicationDeliveryResult, ConversationThreadSummary,
 * App\Support\Email\Providers\OutboundEmail) rather than a loose array.
 *
 * This is presentation-only: it never becomes a persisted row, and
 * fields that don't apply to a given `$type` are simply null (brief
 * §11: "do not force all domain concepts into nullable database
 * columns merely to simplify UI" -- nothing here is a database
 * column at all).
 */
final class CommunicationInboxItem
{
    public function __construct(
        public readonly string $type, // 'conversation'|'announcement'|'template'
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $preview,
        public readonly ?string $actorName,
        public readonly ?Carbon $latestActivityAt,
        public readonly bool $unread,
        public readonly ?string $priority,
        public readonly ?string $requirement,
        public readonly ?string $status,
        public readonly bool $hasAttachments,
        public readonly string $route,
        // Phase 5A.10 §41: always `false` for a conversation/template
        // item -- only an announcement can ever be Emergency. Purely a
        // presentation flag mirrored from
        // App\Domain\Communications\Infrastructure\CommunicationAnnouncement::isEmergency() --
        // never the internal justification, which stays restricted to
        // the announcement detail page's own server-side check.
        public readonly bool $isEmergency = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'preview' => $this->preview,
            'actorName' => $this->actorName,
            'latestActivityAt' => $this->latestActivityAt?->toIso8601String(),
            'unread' => $this->unread,
            'priority' => $this->priority,
            'requirement' => $this->requirement,
            'status' => $this->status,
            'hasAttachments' => $this->hasAttachments,
            'route' => $this->route,
            'isEmergency' => $this->isEmergency,
        ];
    }
}
