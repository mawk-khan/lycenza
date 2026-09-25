<?php

namespace Tests\Feature\Operations;

use App\Jobs\ProcessOutboxEventJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 13): what a maintenance-window release
 * relies on while `php artisan down` is active -- proven, not assumed.
 *
 * - Liveness stays 200 (the process is alive; an orchestrator must not
 *   restart it -- CLAUDE.md rule 55); readiness, pages and the API are 503.
 * - Queue workers do not take jobs (they pause; nothing uses --force).
 * - The scheduler runs no business command (no event opts into
 *   maintenance mode), so nothing works against a half-migrated schema.
 * - The maintenance flag is shared (cache driver), never per container.
 */
class MaintenanceWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Shared-driver semantics without touching the shared tree's
        // storage/framework/down file.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['status' => 503, 'retry' => 60]);
    }

    protected function tearDown(): void
    {
        try {
            $this->app->maintenanceMode()->deactivate();
        } catch (\Throwable) {
            // the unreadable-store case: nothing was stored
        }
        parent::tearDown();
    }

    #[Test]
    public function liveness_stays_up_while_everything_else_is_unavailable(): void
    {
        $this->assertTrue($this->app->isDownForMaintenance());

        $this->getJson('/api/health/live')->assertOk();
        $this->getJson('/api/health/ready')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
        $this->get('/login')->assertStatus(503);
        $this->getJson('/api/v1/me')->assertStatus(503);
        $this->postJson('/api/v1/auth/tokens', [])->assertStatus(503);
    }

    #[Test]
    public function readiness_fails_closed_when_the_maintenance_flag_cannot_be_read(): void
    {
        $this->app->maintenanceMode()->deactivate();
        $this->getJson('/api/health/ready')->assertOk();

        // The flag's store (PostgreSQL in production) is unreachable.
        config(['cache.stores.unreadable' => ['driver' => 'database', 'connection' => 'unreachable', 'table' => 'cache']]);
        config(['database.connections.unreachable' => [...config('database.connections.pgsql'), 'host' => '127.0.0.1', 'port' => '1']]);
        config(['app.maintenance.store' => 'unreadable']);
        $this->app->forgetInstance(MaintenanceModeManager::class);
        $this->app->forgetInstance(MaintenanceMode::class);

        $this->getJson('/api/health/ready')->assertStatus(503);
        $this->getJson('/api/health/live')->assertOk();
    }

    #[Test]
    public function workers_take_no_job_during_maintenance(): void
    {
        config(['queue.connections.maintenance_test' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]]);
        $this->app['queue']->connection('maintenance_test')->pushOn('default', new ProcessOutboxEventJob('00000000-0000-0000-0000-000000000000'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());

        Artisan::call('queue:work', ['connection' => 'maintenance_test', '--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0]);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count(), 'the job is still queued: the worker paused');
        $this->assertStringNotContainsString('--force', (string) file_get_contents(base_path('deploy/entrypoint.sh')));
    }

    #[Test]
    public function no_scheduled_business_command_runs_during_maintenance(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertFalse($event->evenInMaintenanceMode, ($event->command ?? $event->description).' must not run during a maintenance window');
            $this->assertFalse($event->runsInMaintenanceMode());
            $this->assertFalse($event->isDue($this->app), ($event->command ?? $event->description).' would be due during maintenance');
        }
    }
}
