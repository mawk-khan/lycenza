<?php

namespace App\Listeners;

use App\Support\Observability\Logging\LogRuntime;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\SafeException;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 0C.4 section 19: the queue-processing heartbeat, recorded in the
 * SAME `scheduler_heartbeats` table every named heartbeat uses (name:
 * `queue:{queueName}`), one row per queue -- never per worker replica.
 * Registered ONCE, by Laravel's listener discovery of these `handle*`
 * methods (Phase 0O.5A removed a second, explicit registration that
 * double-recorded everything).
 *
 * Successes are THROTTLED (at most one write per queue per
 * THROTTLE_SECONDS, via an atomic `Cache::add()`); failures are not.
 *
 * Phase 0O.5A (ADR 0051 §5, §11): also marks the job class for the log
 * pipeline while it runs (LogRuntime) and counts processed/failed jobs per
 * queue (any queue outside the required set is `other`).
 */
class RecordQueueHeartbeat
{
    private const THROTTLE_SECONDS = 10;

    public function __construct(
        private readonly SchedulerHeartbeatRecorder $heartbeats,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handleProcessing(JobProcessing $event): void
    {
        LogRuntime::startJob($event->job->resolveName());
    }

    public function handleProcessed(JobProcessed $event): void
    {
        LogRuntime::endJob();
        $queue = (string) $event->job->getQueue();
        $this->metrics->counter('lycenza_queue_jobs_processed_total', 1, ['queue' => $this->label($queue)]);

        $name = 'queue:'.$queue;
        if (Cache::add("observability:queue-heartbeat-throttle:{$name}", true, self::THROTTLE_SECONDS)) {
            $this->heartbeats->recordSuccess($name);
        }
    }

    public function handleExceptionOccurred(JobExceptionOccurred $event): void
    {
        LogRuntime::endJob();
    }

    public function handleFailed(JobFailed $event): void
    {
        LogRuntime::endJob();
        $queue = (string) $event->job->getQueue();
        $this->metrics->counter('lycenza_queue_jobs_failed_total', 1, ['queue' => $this->label($queue)]);
        $this->heartbeats->recordFailure('queue:'.$queue, SafeException::code($event->exception));
    }

    private function label(string $queue): string
    {
        return in_array($queue, MetricCatalog::queues(), true) ? $queue : 'other';
    }
}
