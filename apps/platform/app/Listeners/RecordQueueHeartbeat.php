<?php

namespace App\Listeners;

use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 0C.4 section 19: a lightweight queue-processing-alive signal,
 * recorded via the SAME `scheduler_heartbeats` table/recorder every
 * other named heartbeat uses (name: `queue:{queueName}`) -- not a
 * second table. Registered against Laravel's OWN queue lifecycle
 * events (`Queue::after()`/`Queue::failing()` in
 * App\Providers\AppServiceProvider::boot()), not a custom per-job hook
 * every job would need to remember to call.
 *
 * Successes are THROTTLED (section 19: "avoid excessive database
 * writes per job... do not create one database row per successful
 * job") via an atomic `Cache::add()` sentinel -- at most one write per
 * queue per throttle window, regardless of how many jobs that queue
 * actually processes in that window. Failures are NOT throttled (rarer
 * and individually informative -- see
 * App\Support\Observability\OperationalStatusService's queue health
 * classification, which reads `last_error` from this same row).
 */
class RecordQueueHeartbeat
{
    private const THROTTLE_SECONDS = 10;

    public function __construct(private readonly SchedulerHeartbeatRecorder $heartbeats) {}

    public function handleProcessed(JobProcessed $event): void
    {
        $name = 'queue:'.$event->job->getQueue();
        $throttleKey = "observability:queue-heartbeat-throttle:{$name}";

        if (Cache::add($throttleKey, true, self::THROTTLE_SECONDS)) {
            $this->heartbeats->recordSuccess($name);
        }
    }

    public function handleFailed(JobFailed $event): void
    {
        $name = 'queue:'.$event->job->getQueue();

        $this->heartbeats->recordFailure($name, $event->exception->getMessage());
    }
}
