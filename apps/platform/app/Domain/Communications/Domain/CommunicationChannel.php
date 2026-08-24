<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.1 §2.8: channel-neutral delivery target. Only `InApp` has a
 * registered driver in this checkpoint
 * (App\Domain\Communications\Application\Channels\CommunicationChannelRegistry)
 * -- the rest exist so the schema/domain never needs a redesign when a
 * real provider adapter is added later.
 */
enum CommunicationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Push = 'push';
}
