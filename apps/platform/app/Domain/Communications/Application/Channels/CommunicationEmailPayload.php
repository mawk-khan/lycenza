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
    public function __construct(
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $fromAddress,
        public readonly string $fromName,
    ) {}
}
