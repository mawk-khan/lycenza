<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;

/**
 * The only concrete driver registered in Phase 5A.1. "Delivering"
 * in-app means the CommunicationDelivery row existing and being visible
 * in the recipient's Communication Hub inbox -- no I/O, always succeeds.
 */
class InAppChannelDriver implements CommunicationChannelDriver
{
    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::InApp;
    }

    public function send(CommunicationDelivery $delivery): CommunicationDeliveryResult
    {
        return CommunicationDeliveryResult::delivered();
    }
}
