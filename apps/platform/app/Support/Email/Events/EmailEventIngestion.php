<?php

namespace App\Support\Email\Events;

use App\Jobs\ApplyEmailEventJob;
use App\Models\EmailEvent;
use App\Support\Observability\QueueName;
use Symfony\Component\Uid\UuidV7;

/**
 * ADR 0055 section 11.3: stores normalized events, deduplicated by the
 * unique (provider, event_key) index -- a replayed or duplicated delivery
 * is a no-op (no second transition, suppression, metric or audit) -- and
 * queues each NEW event for application. The request path does no more
 * than this.
 */
final class EmailEventIngestion
{
    /**
     * @param  list<NormalizedEmailEvent>  $events
     * @return array{new: int, duplicate: int}
     */
    public function ingest(string $provider, array $events): array
    {
        $new = 0;
        $duplicate = 0;

        foreach ($events as $event) {
            $id = (string) new UuidV7;

            $inserted = EmailEvent::query()->insertOrIgnore([
                'id' => $id,
                'provider' => $provider,
                'event_key' => mb_substr($event->eventKey, 0, 128),
                'type' => $event->type->value,
                'bounce_class' => $event->bounceClass,
                'provider_message_id' => $event->providerMessageId,
                'occurred_at' => $event->occurredAt,
                'received_at' => now(),
                'result' => 'received',
            ]);

            if ($inserted === 0) {
                $duplicate++;

                continue;
            }

            $new++;
            ApplyEmailEventJob::dispatch($id)->onQueue(QueueName::Notifications->value)->afterCommit();
        }

        return ['new' => $new, 'duplicate' => $duplicate];
    }
}
