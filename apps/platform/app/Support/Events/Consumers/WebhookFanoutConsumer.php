<?php

namespace App\Support\Events\Consumers;

use App\Jobs\DeliverWebhookJob;
use App\Models\DomainEventOutbox;
use App\Models\WebhookEndpoint;
use App\Support\Events\EventConsumer;
use App\Support\Observability\QueueName;
use App\Support\Webhooks\WebhookEventRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Fans an event out to every active endpoint actively subscribed to it
 * for its School. Each endpoint's delivery-row CLAIM (not the HTTP call
 * itself) happens in its own SAVEPOINT (nested DB::transaction) so one
 * endpoint's unique-constraint conflict (section 15/20: duplicate
 * delivery-attempt creation) cannot poison the whole outer transaction
 * IdempotentConsumerGuard already wraps this consumer in -- see
 * docs/architecture/adr/0024-real-postgresql-test-infrastructure.md's
 * note on Postgres aborting a transaction on any error within it,
 * which is exactly the failure mode a savepoint avoids.
 *
 * Never makes an HTTP call itself (section 56) -- only claims delivery
 * rows and queues App\Jobs\DeliverWebhookJob, which does the real work
 * asynchronously, outside this consumer's (and the original business
 * transaction's) execution.
 */
class WebhookFanoutConsumer implements EventConsumer
{
    public function __construct(private readonly WebhookEventRegistry $registry) {}

    public function name(): string
    {
        return 'webhook-fanout';
    }

    public function handles(string $eventType): bool
    {
        // Section 12: an internal-only event is never fanned out, no
        // matter what a (theoretically impossible, since subscribing
        // already validates against the same registry) subscription
        // row might claim.
        return $this->registry->isSubscribable($eventType);
    }

    public function handle(DomainEventOutbox $event): void
    {
        if ($event->school_id === null) {
            return;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('school_id', $event->school_id)
            ->where('status', 'active')
            ->whereHas('subscriptions', function ($query) use ($event) {
                $query->where('event_type', $event->event_type)->where('enabled', true);
            })
            ->get();

        foreach ($endpoints as $endpoint) {
            try {
                $delivery = DB::transaction(function () use ($endpoint, $event) {
                    return $endpoint->deliveries()->create([
                        'school_id' => $endpoint->school_id,
                        'event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'status' => 'pending',
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // Already claimed for this (endpoint, event) pair --
                // exactly the section 57 dedup guarantee.
                continue;
            }

            DeliverWebhookJob::dispatch($endpoint->school_id, $delivery->id)
                ->onQueue(QueueName::Integrations->value)
                ->afterCommit();
        }
    }
}
