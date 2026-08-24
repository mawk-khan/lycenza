<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Domain\CommunicationChannel;

/**
 * Channel => driver map, the same "register a driver per channel"
 * shape App\Support\Notifications\NotificationDispatcher already
 * established (foundation doc §1.5) -- bound as a singleton in
 * App\Providers\CommunicationServiceProvider. A channel with no
 * registered driver (Email/Sms/WhatsApp/Push in this checkpoint) is a
 * deliberate "adapter-ready, not adapter-present" state, not a bug.
 */
class CommunicationChannelRegistry
{
    /** @var array<string, CommunicationChannelDriver> */
    private array $drivers = [];

    public function register(CommunicationChannelDriver $driver): void
    {
        $this->drivers[$driver->channel()->value] = $driver;
    }

    public function has(CommunicationChannel $channel): bool
    {
        return isset($this->drivers[$channel->value]);
    }

    public function driver(CommunicationChannel $channel): ?CommunicationChannelDriver
    {
        return $this->drivers[$channel->value] ?? null;
    }
}
