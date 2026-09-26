<?php

namespace Tests\Feature\Observability;

use App\Jobs\WorkerCanaryJob;
use App\Listeners\RecordScheduledTaskRun;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\Metrics\Series;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §11): worker-class canaries and scheduler
 * execution freshness, with deterministic time -- no real worker pool and
 * no monitoring backend.
 */
class SchedulerAndCanaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('scheduler_heartbeats')->where('name', 'like', 'queue:%')->delete();
        app(MetricStore::class)->flush();
    }

    #[Test]
    public function the_scheduler_dispatches_one_canary_per_required_queue_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'worker-canaries');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        Queue::fake();
        Artisan::call('platform:dispatch-worker-canaries');

        foreach (['default', 'integrations', 'notifications'] as $queue) {
            Queue::assertPushedOn($queue, WorkerCanaryJob::class, fn (WorkerCanaryJob $job) => $job->canaryQueue === $queue);
        }
        Queue::assertPushed(WorkerCanaryJob::class, 3);
    }

    #[Test]
    public function a_canary_carries_no_school_and_cannot_storm(): void
    {
        $job = new WorkerCanaryJob('integrations');

        $this->assertSame(1, $job->tries, 'one attempt: a failed canary is not retried');
        $this->assertSame(10, $job->timeout);
        $this->assertSame(600, $job->uniqueFor, 'unique per queue: at most one waits when the worker class is down');
        $this->assertSame('worker-canary:integrations', $job->uniqueId());
        $this->assertSame('integrations', $job->queue);
        $this->assertStringNotContainsString('school', strtolower(serialize($job)));
        $this->assertNotContains(TenantScoped::class, class_uses_recursive($job));
    }

    private function workerProcessed(string $queue): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('resolveName')->andReturn(WorkerCanaryJob::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();
        event(new JobProcessing('redis', $job));
        event(new JobProcessed('redis', $job));
    }

    #[Test]
    public function a_processed_canary_refreshes_only_its_queue_and_a_missing_worker_goes_stale(): void
    {
        // The default and notifications worker classes process their
        // canaries; the integrations worker class is down.
        $this->workerProcessed('default');
        $this->workerProcessed('notifications');

        $this->travel((int) config('observability.thresholds.worker_heartbeat_stale_seconds') - 10)->seconds();
        $status = collect(app(OperationalStatusService::class)->queues())->keyBy->component;
        $this->assertSame(OperationalStatus::Healthy, $status['queue:default']->status);
        $this->assertSame(OperationalStatus::Healthy, $status['queue:notifications']->status);
        $this->assertSame('stalled', $status['queue:integrations']->reason);

        $this->travel(20)->seconds();
        $status = collect(app(OperationalStatusService::class)->queues())->keyBy->component;
        $this->assertSame('stalled', $status['queue:default']->reason, 'no newer canary: stale after the shared threshold');

        $processed = app(MetricStore::class)->all();
        $this->assertSame(1.0, $processed[Series::key('lycenza_queue_jobs_processed_total', ['queue' => 'default'])]);
    }

    #[Test]
    public function every_task_run_is_counted_and_tasks_without_their_own_heartbeat_get_one(): void
    {
        $schedule = app(Schedule::class);
        $expire = collect($schedule->events())->firstWhere('description', 'expire-school-elevations');
        $outbox = collect($schedule->events())->firstWhere('description', 'outbox-dispatch');
        $expire->exitCode = 0;
        $outbox->exitCode = 1;

        event(new ScheduledTaskFinished($expire, 0.25));
        event(new ScheduledTaskFinished($outbox, 0.5));

        $metrics = app(MetricStore::class)->all();
        $this->assertSame(1.0, $metrics[Series::key('lycenza_scheduler_task_runs_total', ['scheduled_task' => 'expire-school-elevations', 'outcome' => 'success'])]);
        $this->assertSame(1.0, $metrics[Series::key('lycenza_scheduler_task_runs_total', ['scheduled_task' => 'outbox-dispatch', 'outcome' => 'failure'])]);
        $this->assertSame(1.0, $metrics[Series::key('lycenza_scheduler_task_duration_seconds_count', ['scheduled_task' => 'expire-school-elevations'])]);

        $this->assertNotNull(DB::table('scheduler_heartbeats')->where('name', 'expire-school-elevations')->value('last_success_at'));
        $this->assertContains('outbox-dispatch', RecordScheduledTaskRun::SELF_RECORDING, 'self-recording commands keep their precise error code');
    }

    #[Test]
    public function operations_status_covers_every_scheduled_task(): void
    {
        $names = collect(app(OperationalStatusService::class)->scheduler())->map->component->all();

        foreach (app(Schedule::class)->events() as $event) {
            $this->assertContains("scheduler:{$event->description}", $names);
        }
    }
}
