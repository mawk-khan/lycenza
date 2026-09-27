<?php

namespace App\Support\Observability\Signals;

use App\Models\DomainEventOutbox;
use App\Models\SchedulerHeartbeat;
use App\Support\Events\OutboxReconciler;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\QueueName;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §11, §15): the ONE place the bounded health and
 * freshness facts are computed. Operations status and the metrics scrape
 * both read these signals, with the same thresholds
 * (`observability.thresholds`), so an operator and an alert never disagree
 * about what "stale" or "overdue" means.
 *
 * Every read is platform-scoped and bounded: heartbeat rows, queue
 * driver counters, the outbox (no RLS), `failed_jobs` grouped by queue and
 * `operational_work_backlog` (no RLS, no tenant data). Nothing here sets a
 * School context, walks Schools, touches a tenant table or an audit
 * ledger. Callers guard each call: a database outage raises, and they
 * degrade per component.
 */
class OperationalSignals
{
    /** recovery source => the scheduled task whose heartbeat is its freshness */
    public const SWEEP_TASKS = [
        'outbox' => 'outbox-dispatch',
        'webhook' => 'webhook-deliveries-redispatch',
        'communication' => 'communication-deliveries-redispatch',
        'automation' => 'automation-executions-redispatch',
        'email' => 'email-messages-redispatch',
    ];

    /**
     * @return list<TaskHeartbeat>
     */
    public function taskHeartbeats(): array
    {
        $rows = SchedulerHeartbeat::query()->whereIn('name', MetricCatalog::scheduledTasks())->get()->keyBy('name');
        $now = now();

        return array_map(function (string $task) use ($rows, $now) {
            $daily = in_array($task, MetricCatalog::DAILY_TASKS, true);
            $staleAfter = (int) config($daily ? 'observability.thresholds.daily_task_stale_seconds' : 'observability.thresholds.minute_task_stale_seconds');
            $criticalAfter = $daily ? 2 * $staleAfter : (int) config('observability.thresholds.minute_task_critical_seconds');
            /** @var SchedulerHeartbeat|null $row */
            $row = $rows->get($task);
            $age = $row?->last_success_at !== null ? (int) $row->last_success_at->diffInSeconds($now, true) : null;

            return new TaskHeartbeat(
                $task,
                $row?->last_success_at,
                $row?->last_error,
                $staleAfter,
                $criticalAfter,
                $age === null || $age > $staleAfter,
                // A daily task that has not run yet on a fresh installation is
                // not critical; a minute task that never succeeded is.
                $age === null ? ! $daily : $age > $criticalAfter,
            );
        }, MetricCatalog::scheduledTasks());
    }

    /**
     * @return list<QueueSignal>
     */
    public function queues(): array
    {
        $staleAfter = (int) config('observability.thresholds.worker_heartbeat_stale_seconds');
        $names = array_map(fn (QueueName $q) => 'queue:'.$q->value, QueueName::requiredWorkerQueues());
        $rows = SchedulerHeartbeat::query()->whereIn('name', $names)->get()->keyBy('name');
        $connection = Queue::connection();
        $now = now();

        return array_map(function (QueueName $queue) use ($rows, $connection, $staleAfter, $now) {
            /** @var SchedulerHeartbeat|null $row */
            $row = $rows->get('queue:'.$queue->value);
            $at = $row?->last_success_at;
            $oldest = $this->probe(fn () => $connection->creationTimeOfOldestPendingJob($queue->value));

            return new QueueSignal(
                $queue->value,
                $this->count(fn () => (int) $connection->pendingSize($queue->value)),
                $this->count(fn () => (int) $connection->delayedSize($queue->value)),
                $this->count(fn () => (int) $connection->reservedSize($queue->value)),
                $oldest === false ? null : (is_numeric($oldest) ? max(0, $now->getTimestamp() - (int) $oldest) : 0),
                $at,
                $at === null || $at->diffInSeconds($now, true) > $staleAfter,
                $row?->last_error,
            );
        }, QueueName::requiredWorkerQueues());
    }

    /**
     * failed_jobs rows per required queue (anything else as `other`).
     *
     * @return array<string, int>
     */
    public function failedJobs(): array
    {
        $counts = array_fill_keys([...MetricCatalog::queues(), 'other'], 0);

        foreach (DB::table('failed_jobs')->selectRaw('queue, count(*) as total')->groupBy('queue')->get() as $row) {
            $key = in_array($row->queue, MetricCatalog::queues(), true) ? $row->queue : 'other';
            $counts[$key] += (int) $row->total;
        }

        return $counts;
    }

    public function outbox(): OutboxSignal
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds(OutboxReconciler::STALE_AFTER_SECONDS);

        $pending = DomainEventOutbox::query()->where('status', 'pending');
        $unacknowledged = DomainEventOutbox::query()->where('status', 'dispatched')->whereNull('processed_at');

        // Age only of events already due: one scheduled for later is waiting
        // legitimately, never backlog.
        $oldestPending = (clone $pending)->where('available_at', '<=', $now)->min('available_at');
        $oldestStale = (clone $unacknowledged)->where('dispatched_at', '<=', $staleBefore)->min('dispatched_at');

        return new OutboxSignal(
            (clone $pending)->count(),
            $oldestPending !== null ? (int) Carbon::parse($oldestPending)->diffInSeconds($now, true) : 0,
            (clone $unacknowledged)->count(),
            (clone $unacknowledged)->where('dispatched_at', '<=', $staleBefore)->count(),
            $oldestStale !== null ? (int) Carbon::parse($oldestStale)->addSeconds(OutboxReconciler::STALE_AFTER_SECONDS)->diffInSeconds($now, true) : 0,
            DomainEventOutbox::query()->where('status', 'failed')->count(),
        );
    }

    /**
     * Unfinished durable work for one source, from operational_work_backlog.
     *
     * OVERDUE means eligible for pick-up but not picked up (ADR 0051 §11):
     * an immediate `pending` item unclaimed for one processing lease; a
     * retrying/queued/pending item whose `next_attempt_at` has passed; an
     * in-flight item whose lease expired. An item still inside its
     * legitimate backoff (future `next_attempt_at`) or a deferred
     * Communication delivery is never overdue.
     */
    public function backlog(string $source): BacklogSignal
    {
        $now = now();
        $states = DB::table('operational_work_backlog')->where('source', $source)
            ->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')
            ->map(fn ($n) => (int) $n)->all();

        $overdue = 0;
        $oldest = null;

        foreach ($this->eligibility($source) as [$state, $column, $graceSeconds]) {
            $query = DB::table('operational_work_backlog')->where('source', $source)->where('state', $state)->whereNotNull($column)
                ->where($column, '<=', $now->copy()->subSeconds($graceSeconds));
            $count = (clone $query)->count();
            if ($count === 0) {
                continue;
            }
            $overdue += $count;
            $since = Carbon::parse((clone $query)->min($column))->addSeconds($graceSeconds);
            $oldest = $oldest === null || $since->lessThan($oldest) ? $since : $oldest;
        }

        return new BacklogSignal($source, $states, $overdue, $oldest !== null ? (int) $oldest->diffInSeconds($now, true) : 0);
    }

    /**
     * Communication `queued` rows whose next attempt is still in the future
     * (deferred -- never overdue).
     */
    public function deferredCommunications(): int
    {
        return DB::table('operational_work_backlog')->where('source', 'communication')->where('state', 'queued')
            ->where(fn ($q) => $q->where('next_attempt_at', '>', now()))->count();
    }

    /**
     * @return array<string, ?CarbonInterface> recovery source => last success
     */
    public function recoverySweeps(): array
    {
        $rows = SchedulerHeartbeat::query()->whereIn('name', array_values(self::SWEEP_TASKS))->get()->keyBy('name');

        return array_map(fn (string $task) => $rows->get($task)?->last_success_at, self::SWEEP_TASKS);
    }

    /**
     * [state, timestamp column, grace seconds] rows that make an item overdue.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function eligibility(string $source): array
    {
        return match ($source) {
            'webhook' => [
                ['pending', 'state_since', (int) config('webhooks.processing_lease_seconds')],
                ['retrying', 'next_attempt_at', 0],
                ['delivering', 'lease_expires_at', 0],
            ],
            'communication' => [
                ['pending', 'state_since', (int) config('communications.delivery.processing_lease_seconds')],
                ['queued', 'next_attempt_at', 0],
                ['sending', 'lease_expires_at', 0],
            ],
            'automation' => [
                ['pending', 'next_attempt_at', 0],
                ['running', 'lease_expires_at', 0],
            ],
            default => [],
        };
    }

    private function count(callable $read): ?int
    {
        $value = $this->probe($read);

        return is_int($value) ? $value : null;
    }

    /** The value, or false when the queue driver could not be read. */
    private function probe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return false;
        }
    }
}
