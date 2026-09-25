<?php

namespace App\Support\Events;

use App\Jobs\ProcessOutboxEventJob;
use App\Models\DomainEventOutbox;
use App\Models\EventConsumerReceipt;
use App\Support\Observability\QueueName;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.4A (ADR 0050 section 10): recovers outbox events whose queued
 * job was lost (a Redis loss or flush). PostgreSQL is the source of truth:
 *
 * - candidates are `dispatched` rows with no `processed_at`, last
 *   dispatched longer ago than the job's full retry lifecycle
 *   (STALE_AFTER_SECONDS) -- so a job that is legitimately queued,
 *   running or backing off is not raced;
 * - each is decided from the consumer receipts: when every consumer
 *   registered for its type already holds a receipt, it is acknowledged
 *   (`processed_at`), not replayed; otherwise ProcessOutboxEventJob is
 *   dispatched again, and IdempotentConsumerGuard's unique receipts make
 *   that duplicate-safe (a consumer that already ran is skipped; a
 *   concurrent duplicate rolls its effect back);
 * - rows are claimed FOR UPDATE SKIP LOCKED in bounded batches, their
 *   `dispatched_at` bumped and `attempts` incremented, so concurrent
 *   reconcilers pick different rows and one row is re-dispatched at most
 *   once per stale window; after MAX_ATTEMPTS it is marked `failed`.
 *
 * It performs no consumer work itself.
 */
class OutboxReconciler
{
    /**
     * ProcessOutboxEventJob: 5 tries x 30 s timeout + 110 s of backoff =
     * 260 s of legitimate life; ten minutes leaves room for queue latency.
     */
    public const STALE_AFTER_SECONDS = 600;

    public const MAX_ATTEMPTS = 25;

    public function __construct(private readonly EventConsumerRegistry $registry) {}

    /**
     * @return array{redispatched: int, acknowledged: int, failed: int}
     */
    public function reconcile(int $batchSize): array
    {
        return DB::transaction(function () use ($batchSize): array {
            $rows = DomainEventOutbox::query()
                ->where('status', 'dispatched')
                ->whereNull('processed_at')
                ->where('dispatched_at', '<=', now()->subSeconds(self::STALE_AFTER_SECONDS))
                ->orderBy('dispatched_at')
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->get();

            $result = ['redispatched' => 0, 'acknowledged' => 0, 'failed' => 0];

            foreach ($rows as $event) {
                if ($this->allConsumersReceipted($event)) {
                    $event->forceFill(['processed_at' => now()])->save();
                    $result['acknowledged']++;

                    continue;
                }

                if ($event->attempts >= self::MAX_ATTEMPTS) {
                    $event->forceFill(['status' => 'failed', 'last_error' => 'reconciliation_attempts_exhausted'])->save();
                    $result['failed']++;

                    continue;
                }

                $event->forceFill(['dispatched_at' => now(), 'attempts' => $event->attempts + 1])->save();

                ProcessOutboxEventJob::dispatch($event->id)
                    ->onQueue(QueueName::Default->value)
                    ->afterCommit();
                $result['redispatched']++;
            }

            return $result;
        });
    }

    private function allConsumersReceipted(DomainEventOutbox $event): bool
    {
        $names = array_map(fn (EventConsumer $consumer) => $consumer->name(), $this->registry->forEventType($event->event_type));

        if ($names === []) {
            return true;
        }

        $receipted = EventConsumerReceipt::query()->where('event_id', $event->id)->whereIn('consumer_name', $names)
            ->distinct()->count('consumer_name');

        return $receipted === count(array_unique($names));
    }
}
