<?php

namespace App\Domain\Communications\Application\Channels;

/**
 * Phase 5A.3 §12: an immutable value object handed to
 * App\Domain\Communications\Application\Channels\CommunicationMail --
 * the rendering layer receives exactly this and nothing else. It never
 * loads a School/User/tenant context itself; every value here was
 * already resolved and authorized by
 * App\Domain\Communications\Application\Channels\EmailChannelDriver
 * before the Mailable is built.
 */
final class CommunicationEmailPayload
{
    /**
     * Phase 5A.6 §28: `$attachments` holds lightweight storage-reference
     * descriptors only -- never raw file bytes -- so this payload never
     * loads a whole attachment into memory itself; CommunicationMail::attachments()
     * streams each one lazily from its disk at send time via
     * Attachment::fromStorageDisk().
     *
     * @param  array<int, array{disk: string, path: string, displayName: string, mimeType: string}>  $attachments
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly array $attachments = [],
    ) {}
}
