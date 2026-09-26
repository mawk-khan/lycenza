<?php

namespace App\Listeners;

use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;

/**
 * Phase 0O.5A (ADR 0051 §11): every scheduled task's execution, from the
 * scheduler's OWN lifecycle events (registered by listener discovery):
 * run counts by outcome and duration for every task in
 * MetricCatalog::scheduledTasks() -- the basis of the duplicate-scheduler
 * signal -- and the task heartbeat for tasks that do not record one
 * themselves. (Tasks whose command records its own heartbeat keep doing
 * so, with a precise error code; this listener does not overwrite it.)
 */
class RecordScheduledTaskRun
{
    /** Commands that record their own named heartbeat. */
    public const SELF_RECORDING = [
        'outbox-dispatch', 'webhook-deliveries-redispatch', 'communication-deliveries-redispatch',
        'communications-publish-scheduled', 'automation-executions-redispatch',
    ];

    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly SchedulerHeartbeatRecorder $heartbeats,
    ) {}

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $task = $this->task($event->task->description);
        if ($task === null) {
            return;
        }

        $succeeded = (int) ($event->task->exitCode ?? 0) === 0;
        $this->metrics->counter('lycenza_scheduler_task_runs_total', 1, ['scheduled_task' => $task, 'outcome' => $succeeded ? 'success' : 'failure']);
        $this->metrics->observe('lycenza_scheduler_task_duration_seconds', (float) $event->runtime, ['scheduled_task' => $task]);

        if (! in_array($task, self::SELF_RECORDING, true)) {
            $succeeded ? $this->heartbeats->recordSuccess($task) : $this->heartbeats->recordFailure($task, 'exit_code_'.(int) $event->task->exitCode);
        }
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $task = $this->task($event->task->description);
        if ($task === null) {
            return;
        }

        $this->metrics->counter('lycenza_scheduler_task_runs_total', 1, ['scheduled_task' => $task, 'outcome' => 'failure']);

        if (! in_array($task, self::SELF_RECORDING, true)) {
            $this->heartbeats->recordFailure($task, class_basename($event->exception));
        }
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        $task = $this->task($event->task->description);

        if ($task !== null) {
            $this->metrics->counter('lycenza_scheduler_task_runs_total', 1, ['scheduled_task' => $task, 'outcome' => 'skipped']);
        }
    }

    private function task(?string $description): ?string
    {
        return is_string($description) && in_array($description, MetricCatalog::scheduledTasks(), true) ? $description : null;
    }
}
