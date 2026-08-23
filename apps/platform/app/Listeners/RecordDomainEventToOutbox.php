<?php

namespace App\Listeners;

use App\Models\DomainEventOutbox;
use App\Support\Events\ShouldBeOutboxed;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\Uid\UuidV7;

/**
 * Registered (in AppServiceProvider::boot()) against the
 * ShouldBeOutboxed INTERFACE, so it fires for every event implementing
 * it without each event needing its own explicit listener wiring.
 * Runs SYNCHRONOUSLY (not queued) -- this is the entire mechanism that
 * makes the outbox transactional: `event()` is called from inside the
 * caller's DB::transaction(), so this listener's INSERT shares that
 * same transaction and connection. If the transaction later rolls
 * back, the INSERT rolls back with it -- the event genuinely never
 * existed. See docs/architecture/adr/0025-transactional-outbox.md.
 */
class RecordDomainEventToOutbox
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ShouldBeOutboxed $event): void
    {
        DomainEventOutbox::query()->create([
            'id' => (string) new UuidV7,
            'event_type' => $event->eventType(),
            'event_version' => $event->eventVersion(),
            'school_id' => $event->schoolId(),
            'campus_id' => $event->campusId(),
            'actor_id' => $this->context->actor()?->id,
            'request_id' => $this->context->requestId(),
            'correlation_id' => $this->context->correlationId() ?? (string) new UuidV7,
            'causation_id' => $event->causationId(),
            'payload' => $event->payload(),
            'metadata' => $event->metadata(),
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
        ]);
    }
}
