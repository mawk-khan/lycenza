<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §11): two replicas of one worker class recording
 * the SAME process-class heartbeat at the same moment -- a genuine
 * two-process race (one write held uncommitted, the other observed
 * blocked on it) -- both succeed and leave exactly one row. No sleeps.
 */
class HeartbeatConcurrencyTest extends TestCase
{
    use ForcesConcurrentOverlap;

    protected array $connectionsToTransact = [];

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->name = 'queue:race-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DB::table('scheduler_heartbeats')->where('name', $this->name)->delete();
        parent::tearDown();
    }

    #[Test]
    public function concurrent_first_heartbeats_never_collide(): void
    {
        $script = base_path('tests/Support/record-heartbeat.php');

        [$holder, $contender] = $this->raceWithHeldHolder(['php', $script, $this->name], ['php', $script, $this->name]);

        $this->assertStringContainsString('recorded', $holder);
        $this->assertStringContainsString('recorded', $contender);
        $this->assertSame(1, DB::table('scheduler_heartbeats')->where('name', $this->name)->count());
        $this->assertNotNull(DB::table('scheduler_heartbeats')->where('name', $this->name)->value('last_success_at'));
    }
}
