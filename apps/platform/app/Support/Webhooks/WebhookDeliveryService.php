<?php

namespace App\Support\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\QueueName;
use InvalidArgumentException;

/**
 * Manual redelivery (section 48): reuses the SAME logical delivery
 * identity (webhook_endpoint_id, event_id) rather than creating a new
 * row -- that pair is uniquely constrained (section 15), so a genuinely
 * new row for the same pair is impossible by design, and isn't needed:
 * resetting the existing row's status back to `pending` and
 * re-dispatching DeliverWebhookJob gives it a fresh attempt while
 * `attempts`/webhook_delivery_attempts history from every PRIOR attempt
 * is left completely untouched (a redelivery's new attempt continues
 * the same attempt_number sequence, never renumbers or deletes history).
 *
 * Only allowed from a TERMINAL state -- redelivering a delivery that's
 * still `pending`/`delivering`/`retrying` would race with its own
 * in-flight lifecycle.
 */
class WebhookDeliveryService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function redeliver(WebhookDelivery $delivery, ?User $actor = null): void
    {
        if (! $delivery->isTerminal()) {
            throw new InvalidArgumentException('Only a delivered, failed, or abandoned delivery may be manually redelivered.');
        }

        $delivery->update([
            'status' => 'pending',
            'processing_lease_expires_at' => null,
            'next_attempt_at' => null,
            'delivered_at' => null,
        ]);

        $this->audit->school($delivery->school, 'integrations.webhook_delivery.redelivery_requested', actor: $actor, subject: $delivery, metadata: [
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'event_id' => $delivery->event_id,
        ]);

        DeliverWebhookJob::dispatch($delivery->school_id, $delivery->id)
            ->onQueue(QueueName::Integrations->value)
            ->afterCommit();
    }
}
