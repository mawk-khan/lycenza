<?php

namespace App\Support\Notifications;

use App\Models\Notification;
use App\Models\School;
use App\Models\User;
use InvalidArgumentException;

class NotificationDispatcher
{
    /** @var array<string, NotificationProvider> */
    private array $providers = [];

    public function registerProvider(NotificationProvider $provider): void
    {
        $this->providers[$provider->channel()] = $provider;
    }

    /**
     * Creates the notification intent and immediately attempts
     * delivery via the registered provider for its channel. A future
     * queued-retry path would instead only create the row here and let
     * a job call attempt(); Phase 0C keeps this synchronous for
     * simplicity since no real provider exists to be slow/unreliable.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(
        School $school,
        ?User $recipient,
        string $channel,
        string $templateKey,
        array $payload = [],
        ?string $triggeringEventId = null,
        ?string $correlationId = null,
    ): Notification {
        if (! isset($this->providers[$channel])) {
            throw new InvalidArgumentException("No notification provider registered for channel '{$channel}'.");
        }

        $notification = Notification::query()->create([
            'school_id' => $school->id,
            'recipient_user_id' => $recipient?->id,
            'channel' => $channel,
            'template_key' => $templateKey,
            'payload' => $payload,
            'status' => 'pending',
            'triggering_event_id' => $triggeringEventId,
            'correlation_id' => $correlationId,
        ]);

        return $this->attempt($notification);
    }

    public function attempt(Notification $notification): Notification
    {
        $provider = $this->providers[$notification->channel]
            ?? throw new InvalidArgumentException("No notification provider registered for channel '{$notification->channel}'.");

        $result = $provider->send($notification);

        $notification->update($result->success
            ? ['status' => 'sent', 'sent_at' => now()]
            : ['status' => 'failed', 'last_error' => $result->error, 'attempts' => $notification->attempts + 1]);

        return $notification->fresh();
    }
}
