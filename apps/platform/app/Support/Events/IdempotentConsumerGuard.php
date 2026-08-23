<?php

namespace App\Support\Events;

use App\Models\DomainEventOutbox;
use App\Models\EventConsumerReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The reusable idempotency primitive for event consumers (section 11).
 * Authoritative deduplication is the database UNIQUE constraint on
 * (consumer_name, event_id), NOT the exists() pre-check below (that
 * check is purely a fast path to skip redundant work in the common,
 * non-racing case).
 *
 * Correctness under concurrency/crash (sections 51, 71): the
 * consumer's side effect and its receipt are written in the SAME
 * transaction. If two workers race, both may pass the pre-check, but
 * only one's transaction can successfully insert the receipt -- the
 * other's UNIQUE violation rolls back its ENTIRE transaction,
 * including whatever side effect it just performed. If a worker
 * crashes before its transaction commits, nothing persisted at all and
 * a retry starts clean. If it crashes after commit but before the
 * queue acknowledges the job, redelivery finds the receipt already
 * there and no-ops. There is no window in which the side effect
 * persists without its receipt, or vice versa.
 */
class IdempotentConsumerGuard
{
    public function run(EventConsumer $consumer, DomainEventOutbox $event): void
    {
        if ($this->alreadyProcessed($consumer, $event)) {
            Log::info('event_consumer.duplicate_skipped', [
                'consumer' => $consumer->name(),
                'event_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($consumer, $event): void {
                $consumer->handle($event);

                EventConsumerReceipt::query()->create([
                    'consumer_name' => $consumer->name(),
                    'event_id' => $event->id,
                    'school_id' => $event->school_id,
                    'processed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('event_consumer.lost_race_rolled_back', [
                'consumer' => $consumer->name(),
                'event_id' => $event->id,
            ]);
        }
    }

    private function alreadyProcessed(EventConsumer $consumer, DomainEventOutbox $event): bool
    {
        return EventConsumerReceipt::query()
            ->where('consumer_name', $consumer->name())
            ->where('event_id', $event->id)
            ->exists();
    }
}
