<?php

namespace App\Support\Email\Providers;

use App\Support\Email\EmailKind;
use App\Support\Email\EmailPurpose;

/**
 * ADR 0055 section 13: everything an adapter may put on the wire, and
 * nothing else. One recipient; a closed header set (From, To, Subject,
 * Date, Message-ID, MIME-Version, plus `Auto-Submitted` on critical mail
 * and whatever the provider itself requires, added inside the adapter);
 * no Reply-To, Cc, Bcc or caller-defined header exists in this type.
 * Text is authoritative; `html` is an escaped repository template or null.
 */
final class OutboundEmail
{
    /**
     * @param  list<array{disk: string, path: string, name: string, mime: string}>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $rfcMessageId,
        public readonly string $idempotencyKey,
        public readonly EmailPurpose $purpose,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly array $attachments = [],
    ) {}

    /** @return array<string, string> */
    public function additionalHeaders(): array
    {
        return $this->purpose->kind() === EmailKind::Critical ? ['Auto-Submitted' => 'auto-generated'] : [];
    }
}
