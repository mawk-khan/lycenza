<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a delivery is requested on a CommunicationChannel with no
 * registered driver (Email/Sms/WhatsApp/Push in Phase 5A.1 -- see
 * App\Domain\Communications\Application\Channels\CommunicationChannelRegistry).
 */
class UnsupportedChannelException extends CommunicationException
{
    public function __construct(string $channel)
    {
        parent::__construct("No delivery driver is registered for channel '{$channel}' yet.");
    }
}
