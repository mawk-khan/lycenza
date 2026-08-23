<?php

namespace App\Support\Events\Consumers;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\User;
use App\Support\Events\EventConsumer;
use App\Support\Notifications\NotificationDispatcher;

/**
 * Phase 0C demonstration consumer: reacts to school.setting.changed.v1
 * by sending the actor an in-app confirmation. Proves "a future module
 * emits an event without knowing downstream consumers are
 * notifications" (section 4) end to end.
 */
class NotifyActorOfSettingChangeConsumer implements EventConsumer
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function name(): string
    {
        return 'notify-actor-of-setting-change';
    }

    public function handles(string $eventType): bool
    {
        return $eventType === 'school.setting.changed.v1';
    }

    public function handle(DomainEventOutbox $event): void
    {
        if ($event->actor_id === null || $event->school_id === null) {
            return;
        }

        $school = School::query()->find($event->school_id);
        $actor = User::query()->find($event->actor_id);

        if ($school === null || $actor === null) {
            return;
        }

        $this->notifications->send(
            school: $school,
            recipient: $actor,
            channel: 'in_app',
            templateKey: 'setting_changed_confirmation',
            payload: $event->payload,
            triggeringEventId: $event->id,
            correlationId: $event->correlation_id,
        );
    }
}
