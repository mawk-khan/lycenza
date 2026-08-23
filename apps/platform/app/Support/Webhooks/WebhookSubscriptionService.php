<?php

namespace App\Support\Webhooks;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookSubscription;
use App\Support\Audit\AuditRecorder;
use InvalidArgumentException;

/**
 * The only sanctioned write path for webhook subscriptions (section
 * 11). Validates the event type against the closed, externally
 * publishable catalog (section 12) BEFORE a subscription can ever be
 * created -- an internal-only event can never become a subscription no
 * matter what a client requests.
 */
class WebhookSubscriptionService
{
    public function __construct(
        private readonly WebhookEventRegistry $registry,
        private readonly AuditRecorder $audit,
    ) {}

    public function subscribe(WebhookEndpoint $endpoint, string $eventType, ?User $actor = null): WebhookSubscription
    {
        if (! $this->registry->isSubscribable($eventType)) {
            throw new InvalidArgumentException("Event type '{$eventType}' is not externally subscribable.");
        }

        $subscription = WebhookSubscription::query()->updateOrCreate(
            ['webhook_endpoint_id' => $endpoint->id, 'event_type' => $eventType],
            ['school_id' => $endpoint->school_id, 'enabled' => true],
        );

        $this->audit->school($endpoint->school, 'integrations.webhook_subscription.created', actor: $actor, subject: $subscription, metadata: [
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
        ]);

        return $subscription;
    }

    public function unsubscribe(WebhookSubscription $subscription, ?User $actor = null): void
    {
        $this->audit->school($subscription->school, 'integrations.webhook_subscription.deleted', actor: $actor, subject: $subscription, metadata: [
            'webhook_endpoint_id' => $subscription->webhook_endpoint_id,
            'event_type' => $subscription->event_type,
        ]);

        $subscription->delete();
    }
}
