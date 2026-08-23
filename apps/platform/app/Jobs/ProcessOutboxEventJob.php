<?php

namespace App\Jobs;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Events\EventConsumerRegistry;
use App\Support\Events\IdempotentConsumerGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched by `platform:outbox-dispatch` (afterCommit) for every
 * pending outbox row. Loads the event, establishes its OWN School
 * context (from the event's own school_id, not whatever the
 * dispatcher's ambient context was -- there is none, the dispatcher is
 * a central/cross-School process), and runs every registered consumer
 * for its event type through IdempotentConsumerGuard.
 *
 * Deliberately does NOT use the TenantScoped trait: that trait
 * captures context from the DISPATCHING request/job, which is wrong
 * here -- this job's tenant context comes from the event it is
 * processing, resolved fresh at handle() time.
 */
class ProcessOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly string $eventId) {}

    public function handle(EventConsumerRegistry $registry, IdempotentConsumerGuard $guard, TenantContext $context): void
    {
        $event = DomainEventOutbox::query()->find($this->eventId);

        if ($event === null) {
            // Nothing to do -- not an error (e.g. a retention sweep
            // could plausibly have pruned it; see EVENTS.md retention).
            return;
        }

        try {
            if ($event->school_id !== null) {
                $school = School::query()->find($event->school_id);
                if ($school !== null) {
                    $context->set($school);
                }
            }

            $context->setRequestId($event->request_id);
            $context->setCorrelationId($event->correlation_id);

            // One consumer's failure must not block sibling consumers
            // for the same event (section 12: isolating consumers
            // keeps one broken integration from starving unrelated
            // ones, e.g. notifications). Every consumer gets a chance
            // to run; failures are collected and re-thrown together
            // AFTER the loop, so the job still fails and Laravel
            // retries it -- a retry is safe because successful
            // consumers are already receipted (IdempotentConsumerGuard)
            // and will no-op on the next attempt.
            $failures = [];

            foreach ($registry->forEventType($event->event_type) as $consumer) {
                try {
                    $guard->run($consumer, $event);
                } catch (\Throwable $e) {
                    Log::error('event_consumer.failed', [
                        'consumer' => $consumer->name(),
                        'event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'error' => $e->getMessage(),
                    ]);

                    $failures[] = "{$consumer->name()}: {$e->getMessage()}";
                }
            }

            if ($failures !== []) {
                throw new \RuntimeException(
                    "One or more consumers failed for event {$event->id}: ".implode('; ', $failures)
                );
            }
        } finally {
            $context->clearAll();
        }
    }
}
