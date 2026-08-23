<?php

namespace App\Support\Events;

/**
 * The set of registered EventConsumers, resolved by event type.
 * Registered in AppServiceProvider so future modules add themselves in
 * one place rather than each inventing its own dispatch mechanism.
 */
class EventConsumerRegistry
{
    /** @var array<int, EventConsumer> */
    private array $consumers = [];

    public function register(EventConsumer $consumer): void
    {
        $this->consumers[] = $consumer;
    }

    /**
     * @return array<int, EventConsumer>
     */
    public function forEventType(string $eventType): array
    {
        return array_values(array_filter(
            $this->consumers,
            fn (EventConsumer $consumer) => $consumer->handles($eventType),
        ));
    }
}
