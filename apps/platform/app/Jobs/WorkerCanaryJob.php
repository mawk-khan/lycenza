<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Phase 0O.5A (ADR 0051 §11): the worker-class canary. The scheduler
 * dispatches one to each required queue every minute; processing it
 * refreshes that queue's `queue:{name}` heartbeat (through
 * App\Listeners\RecordQueueHeartbeat, like every processed job), which is
 * what proves the worker class is alive even when the queue is otherwise
 * idle.
 *
 * No business effect, no School context, no tenant or person data, no
 * payload beyond the queue name. One attempt, a short timeout, and unique
 * per queue for ten minutes: when a worker class is down, canaries do not
 * pile up (at most one waits) and a failed canary is not an incident --
 * the stale heartbeat is the signal.
 */
final class WorkerCanaryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $canaryQueue)
    {
        $this->onQueue($canaryQueue);
    }

    public function uniqueId(): string
    {
        return 'worker-canary:'.$this->canaryQueue;
    }

    public function handle(): void
    {
        // Intentionally empty: being processed is the whole point.
    }
}
