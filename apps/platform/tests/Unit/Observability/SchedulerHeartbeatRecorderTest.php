<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\SchedulerHeartbeatRecorder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 sections 9/39: the primitive both scheduler-heartbeat and
 * queue-heartbeat staleness detection are built on. A fresh success is
 * never stale; an old success (or a heartbeat that never succeeded at
 * all) is.
 */
class SchedulerHeartbeatRecorderTest extends TestCase
{
    #[Test]
    public function a_heartbeat_that_has_never_run_is_absent(): void
    {
        $recorder = new SchedulerHeartbeatRecorder;

        $this->assertNull($recorder->get('never-run-task'));
    }

    #[Test]
    public function recording_success_makes_the_heartbeat_fresh(): void
    {
        $recorder = new SchedulerHeartbeatRecorder;

        $recorder->recordSuccess('outbox-dispatch-test');
        $heartbeat = $recorder->get('outbox-dispatch-test');

        $this->assertNotNull($heartbeat);
        $this->assertNull($heartbeat->last_error);
        $this->assertFalse($recorder->isStale($heartbeat, 300));
    }

    #[Test]
    public function a_heartbeat_older_than_the_threshold_is_stale(): void
    {
        $recorder = new SchedulerHeartbeatRecorder;

        $this->travelTo(now()->subMinutes(10));
        $recorder->recordSuccess('stale-task-test');
        $this->travelBack();

        $heartbeat = $recorder->get('stale-task-test');

        $this->assertTrue($recorder->isStale($heartbeat, 300));
    }

    #[Test]
    public function recording_a_failure_preserves_the_previous_success_timestamp_but_records_the_error(): void
    {
        $recorder = new SchedulerHeartbeatRecorder;

        $recorder->recordSuccess('flaky-task-test');
        $recorder->recordFailure('flaky-task-test', 'boom');

        $heartbeat = $recorder->get('flaky-task-test');

        $this->assertNotNull($heartbeat->last_success_at);
        $this->assertSame('boom', $heartbeat->last_error);
    }

    #[Test]
    public function a_heartbeat_that_has_only_ever_failed_is_stale(): void
    {
        $recorder = new SchedulerHeartbeatRecorder;

        $recorder->recordFailure('always-failing-task-test', 'nope');
        $heartbeat = $recorder->get('always-failing-task-test');

        $this->assertTrue($recorder->isStale($heartbeat, 300));
    }
}
