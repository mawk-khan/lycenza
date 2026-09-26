<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\ComponentStatus;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 20, redefined in Phase 0O.5A (ADR 0051 §11): every
 * required queue receives a no-op canary each minute, so the `queue:{name}`
 * heartbeat proves its worker class is processing whether or not other
 * work is waiting. A stale or missing heartbeat is `stalled` (alert
 * OBS-08); a fresh one with failed jobs is only degraded.
 */
class QueueHealthTest extends TestCase
{
    #[Test]
    public function every_required_queue_is_reported(): void
    {
        $names = array_map(fn (ComponentStatus $c) => $c->component, app(OperationalStatusService::class)->queues());

        $this->assertSame(['queue:default', 'queue:integrations', 'queue:notifications'], $names);
    }

    #[Test]
    public function a_fresh_heartbeat_is_healthy(): void
    {
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:default');

        $this->assertSame(OperationalStatus::Healthy, $this->componentFor('queue:default')->status);
    }

    #[Test]
    public function a_stale_heartbeat_is_unhealthy_and_marked_stalled(): void
    {
        $this->travelTo(now()->subMinutes(15));
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:notifications');
        $this->travelBack();

        $status = $this->componentFor('queue:notifications');

        $this->assertSame(OperationalStatus::Unhealthy, $status->status);
        $this->assertSame('stalled', $status->reason);
    }

    #[Test]
    public function no_heartbeat_at_all_is_stalled(): void
    {
        DB::table('scheduler_heartbeats')->where('name', 'queue:integrations')->delete();

        $this->assertSame('stalled', $this->componentFor('queue:integrations')->reason);
    }

    #[Test]
    public function the_threshold_is_the_shared_worker_heartbeat_value(): void
    {
        $this->travelTo(now()->subSeconds((int) config('observability.thresholds.worker_heartbeat_stale_seconds') - 5));
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:default');
        $this->travelBack();

        $this->assertSame(OperationalStatus::Healthy, $this->componentFor('queue:default')->status);
    }

    #[Test]
    public function failed_jobs_with_a_fresh_heartbeat_are_degraded_not_unhealthy(): void
    {
        app(SchedulerHeartbeatRecorder::class)->recordSuccess('queue:default');
        $this->insertFailedJob('default');

        $status = $this->componentFor('queue:default');

        $this->assertSame(OperationalStatus::Degraded, $status->status);
        $this->assertSame(1, $status->detail['failed']);
    }

    private function componentFor(string $name): ComponentStatus
    {
        foreach (app(OperationalStatusService::class)->queues() as $component) {
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
