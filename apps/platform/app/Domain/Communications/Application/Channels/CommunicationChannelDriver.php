<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;

/**
 * Phase 5A.1 §2.8: the provider boundary a future Email/SMS/WhatsApp/
 * Push adapter implements. Deliberately not the same interface as
 * App\Support\Notifications\NotificationProvider -- that contract's
 * send() takes a Notification model, a different (simpler, single-
 * recipient, no-attempt-history) concept documented in the foundation
 * doc §1.5.
 */
interface CommunicationChannelDriver
{
    public function channel(): CommunicationChannel;

    public function send(CommunicationDelivery $delivery): CommunicationDeliveryResult;
}
