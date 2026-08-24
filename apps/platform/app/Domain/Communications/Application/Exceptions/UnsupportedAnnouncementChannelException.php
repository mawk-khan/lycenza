<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when an Announcement draft requests a delivery channel not
 * yet requestable (Phase 5A.3 §16: only `in_app`/`email` today, even
 * though App\Domain\Communications\Domain\CommunicationChannel already
 * has Sms/WhatsApp/Push cases reserved for a later checkpoint) --
 * fails clean at the Application layer rather than surfacing the
 * communication_announcement_channels_channel_check database
 * constraint violation directly.
 */
class UnsupportedAnnouncementChannelException extends CommunicationException
{
    public function __construct(string $channel)
    {
        parent::__construct("The '{$channel}' delivery channel is not available for Announcements yet.");
    }
}
