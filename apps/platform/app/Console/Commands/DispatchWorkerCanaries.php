<?php

namespace App\Console\Commands;

use App\Jobs\WorkerCanaryJob;
use App\Support\Observability\QueueName;
use Illuminate\Console\Command;

/**
 * Phase 0O.5A (ADR 0051 §11): dispatches one WorkerCanaryJob to each
 * required worker queue (`default`, `integrations`, `notifications` --
 * QueueName::requiredWorkerQueues(), guard-tested against
 * deploy/processes.json). Scheduled every minute as `worker-canaries`.
 */
class DispatchWorkerCanaries extends Command
{
    protected $signature = 'platform:dispatch-worker-canaries';

    protected $description = 'Dispatch one no-op canary job to each required worker queue (worker-class heartbeat).';

    public function handle(): int
    {
        foreach (QueueName::requiredWorkerQueues() as $queue) {
            WorkerCanaryJob::dispatch($queue->value);
        }

        $this->info('Dispatched '.count(QueueName::requiredWorkerQueues()).' worker canaries.');

        return self::SUCCESS;
    }
}
