<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §15): operations status degrades per component
 * when PostgreSQL is unreachable -- the CLI is the outage diagnostic path,
 * so it must report, not crash. Not transactional: the default
 * connection is pointed at a closed port for the duration of the test.
 */
class OperationsStatusOutageTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config('database.connections.pgsql');
        config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => '1']);
        DB::purge('pgsql');
    }

    protected function tearDown(): void
    {
        config(['database.connections.pgsql' => $this->original]);
        DB::purge('pgsql');
        parent::tearDown();
    }

    #[Test]
    public function the_cli_reports_the_database_unavailable_instead_of_crashing(): void
    {
        $exit = Artisan::call('platform:operations-status');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/database\s*\|\s*unhealthy/', $output);
        $this->assertNotSame(0, $exit, 'an unhealthy platform is a non-zero exit');
        foreach (['SQLSTATE', 'Connection refused', '127.0.0.1', 'password'] as $detail) {
            $this->assertStringNotContainsString($detail, $output);
        }
    }

    #[Test]
    public function every_component_degrades_instead_of_throwing(): void
    {
        $components = app(OperationalStatusService::class)->full();

        $this->assertNotEmpty($components);
        $byName = collect($components)->keyBy->component;
        $this->assertSame(OperationalStatus::Unhealthy, $byName['database']->status);
        foreach ($components as $component) {
            $this->assertStringNotContainsString('SQLSTATE', json_encode($component->toArray()));
        }
    }
}
