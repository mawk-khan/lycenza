<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\ComponentStatus;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 20: an idle queue (zero pending) is ALWAYS
 * healthy regardless of heartbeat age; a queue with pending work is
 * only "stalled" once its processing heartbeat has also gone stale.
 * `Queue::size()` is faked here because the `sync` queue connection
 * used in tests (ADR 0024) always reports 0 -- the real behaviour
 * being tested is OperationalStatusService's INTERPRETATION of a given
 * (pending, heartbeat) pair, not the queue driver itself.
 */
class QueueHealthTest extends TestCase
{
    #[Test]
    public function an_idle_queue_with_no_heartbeat_is_healthy(): void
    {
        Queue::shouldReceive('size')->andReturn(0);

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Healthy, $status->status);
        $this->assertSame(0, $status->detail['pending']);
    }

    #[Test]
    public function pending_work_with_a_fresh_heartbeat_is_healthy(): void
    {
        Queue::shouldReceive('size')->andReturn(5);
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:default');

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Healthy, $status->status);
    }

    #[Test]
    public function pending_work_with_a_stale_heartbeat_is_unhealthy_and_marked_stalled(): void
    {
        Queue::shouldReceive('size')->andReturn(5);

        $this->travelTo(now()->subMinutes(15));
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:default');
        $this->travelBack();

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Unhealthy, $status->status);
        $this->assertSame('stalled', $status->reason);
    }

    #[Test]
    public function pending_work_with_no_heartbeat_at_all_is_unhealthy(): void
    {
        Queue::shouldReceive('size')->andReturn(5);

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Unhealthy, $status->status);
    }

    #[Test]
    public function an_idle_queue_with_failed_jobs_is_degraded_not_unhealthy(): void
    {
        Queue::shouldReceive('size')->andReturn(0);
        $this->insertFailedJob('default');

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Degraded, $status->status);
        $this->assertSame(1, $status->detail['failed']);
    }

    private function componentFor(string $name): ComponentStatus
    {
        $components = app(OperationalStatusService::class)->queues();

        foreach ($components as $component) {
            if ($component->component === $name) {
                return $component;
            }
        }

        $this->fail("No component named {$name} found.");
    }

    private function insertFailedJob(string $queue): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'sync',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => "RuntimeException: boom\n#0 {main}",
            'failed_at' => now(),
        ]);
    }
}
