<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\Metrics\MetricsEndpoint;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\Signals\OperationalSignals;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §9-§11): the private metrics endpoint.
 * No Laravel route serves it; the private nginx listener marks requests
 * with LYCENZA_METRICS_LISTENER=1 and the bearer scrape token is required.
 */
class MetricsEndpointTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const TOKEN = 'metrics-test-token-8e1f2a3b4c5d6e7f8091a2b3c4d5e6f7';

    protected function setUp(): void
    {
        parent::setUp();
        config(['observability.metrics.scrape_token' => self::TOKEN]);
        app(MetricStore::class)->flush();
    }

    private function scrape(?string $token = self::TOKEN, bool $listener = true, string $method = 'GET'): Response
    {
        $server = $listener ? ['LYCENZA_METRICS_LISTENER' => '1'] : [];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return app(MetricsEndpoint::class)->respond(Request::create('/metrics', $method, server: $server));
    }

    #[Test]
    public function the_public_application_has_no_metrics_route(): void
    {
        $this->get('http://localhost/metrics')->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])->get('http://localhost/metrics')->assertNotFound();
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('metrics', $route->uri());
        }
    }

    #[Test]
    public function only_the_private_listener_with_the_token_is_served(): void
    {
        $this->assertSame(404, $this->scrape(listener: false)->getStatusCode());
        $this->assertSame(404, $this->scrape(method: 'POST')->getStatusCode());

        $missing = $this->scrape(null);
        $wrong = $this->scrape('metrics-test-token-8e1f2a3b4c5d6e7f8091a2b3c4d5e6f8');
        config(['observability.metrics.scrape_token' => '']);
        $unconfigured = $this->scrape('');
        config(['observability.metrics.scrape_token' => self::TOKEN]);

        foreach ([$missing, $wrong, $unconfigured] as $refused) {
            $this->assertSame(401, $refused->getStatusCode());
            $this->assertSame('', $refused->getContent(), 'identical, empty refusal: nothing reveals how close a token was');
        }

        $ok = $this->scrape();
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $ok->headers->get('Content-Type'));
        $this->assertSame('no-store, private', $ok->headers->get('Cache-Control'));
        $this->assertStringContainsString('# TYPE lycenza_queue_pending_jobs gauge', (string) $ok->getContent());
    }

    #[Test]
    public function the_exposition_is_operational_only_and_carries_no_identifier(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        app(MetricsRecorder::class)->counter('lycenza_http_rejections_total', 1, ['request_surface' => 'api_v1', 'code' => '403']);

        $body = (string) $this->scrape()->getContent();

        foreach ([$school->id, $user->id, $user->email, self::TOKEN, 'SELECT', 'select ', 'Exception', config('app.key')] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $body);
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $body, 'no UUID anywhere');
        $this->assertStringContainsString('lycenza_http_rejections_total{request_surface="api_v1",code="403"} 1', $body);
        $this->assertStringContainsString('lycenza_outbox_pending_events ', $body);

        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^lycenza_[a-z_]+(\{[a-z_]+="[^"]*"(,[a-z_]+="[^"]*")*\})? -?[0-9.e+INF]+$/', $line);
        }
    }

    #[Test]
    public function a_scrape_never_enters_a_school_context_and_its_cost_does_not_grow_with_schools(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->scrape();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->createSchool();
        $baseline = $count();
        foreach (range(1, 6) as $_) {
            $this->createSchool();
        }

        $this->assertSame($baseline, $count(), 'bounded aggregate queries only -- no per-School walk');
        $this->assertLessThan(40, $baseline);
        $this->assertNull(app(TenantContext::class)->school());
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('set_config', $query['query']);
        }
    }

    #[Test]
    public function a_failing_collector_is_isolated_and_counted(): void
    {
        $this->mock(OperationalSignals::class, function ($mock): void {
            $mock->shouldReceive('taskHeartbeats')->andThrow(new RuntimeException('boom'));
            $mock->shouldReceive('recoverySweeps')->andReturn([]);
            $mock->shouldReceive('queues')->andThrow(new RuntimeException('boom'));
            $mock->shouldReceive('failedJobs')->andReturn([]);
            $mock->shouldReceive('outbox')->andThrow(new RuntimeException('boom'));
            $mock->shouldReceive('backlog')->andThrow(new RuntimeException('boom'));
            $mock->shouldReceive('deferredCommunications')->andReturn(0);
        });

        $response = $this->scrape();
        $body = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('lycenza_metrics_collection_errors_total{component="queues"} 1', $body);
        $this->assertStringContainsString('lycenza_metrics_collection_errors_total{component="outbox"} 1', $body);
        $this->assertStringContainsString('lycenza_readiness_status{dependency="postgresql"} 1', $body, 'the healthy parts still render');
        $this->assertStringNotContainsString('boom', $body);
    }

    #[Test]
    public function metrics_stay_available_during_a_maintenance_window(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate([]);

        try {
            $this->assertSame(200, $this->scrape()->getStatusCode());
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    #[Test]
    public function every_exposed_family_is_in_the_catalog(): void
    {
        $body = (string) $this->scrape()->getContent();
        preg_match_all('/^# TYPE ([a-z_]+) /m', $body, $families);

        $this->assertNotEmpty($families[1]);
        $this->assertSame([], array_diff($families[1], array_keys(MetricCatalog::definitions())));
    }
}
