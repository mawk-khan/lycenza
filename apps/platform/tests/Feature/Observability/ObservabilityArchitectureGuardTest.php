<?php

namespace Tests\Feature\Observability;

use App\Listeners\RecordScheduledTaskRun;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051): structural guards for the observability
 * boundary. (Metric-label privacy: MetricCatalogGuardTest; no public
 * metrics route: MetricsEndpointTest; one heartbeat listener:
 * ObservabilityDefectReproductionTest; exception arguments and the private
 * listener in the image: ProductionImageContractTest.)
 */
class ObservabilityArchitectureGuardTest extends TestCase
{
    private function repo(string $path): string
    {
        return (string) file_get_contents(dirname(base_path(), 2).'/'.$path);
    }

    #[Test]
    public function no_vendor_telemetry_or_tracing_sdk_is_a_dependency(): void
    {
        $composer = strtolower((string) file_get_contents(base_path('composer.json')));
        $gateway = strtolower($this->repo('services/ai/requirements.txt'));

        foreach (['datadog', 'newrelic', 'new-relic', 'sentry', 'bugsnag', 'honeybadger', 'splunk', 'open-telemetry/', 'opentelemetry', 'promphp', 'jaeger', 'zipkin', 'elastic/apm'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $composer, "composer.json: {$vendor}");
            $this->assertStringNotContainsString($vendor, $gateway, "requirements.txt: {$vendor}");
        }
    }

    #[Test]
    public function no_application_code_logs_or_stores_raw_exception_text(): void
    {
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();

            $this->assertDoesNotMatchRegularExpression('/Log::\w+\([^;]*getMessage\(\)/s', $source, $file->getRelativePathname().' logs an exception message');
            $this->assertDoesNotMatchRegularExpression("/'error' => \\\$\w+->getMessage\(\)/", $source, $file->getRelativePathname());
            $this->assertDoesNotMatchRegularExpression('/recordFailure\([^;]*getMessage\(\)/', $source, $file->getRelativePathname().' stores exception text in a heartbeat');
            $this->assertDoesNotMatchRegularExpression('/->error\("[^"]*\{\$e->getMessage\(\)\}/', $source, $file->getRelativePathname().' prints exception text to the console');
        }
    }

    #[Test]
    public function every_scheduled_task_has_heartbeat_coverage(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertContains($event->description, MetricCatalog::scheduledTasks(), "{$event->description} is unknown to the catalog (no heartbeat, no metric)");

            if (in_array($event->description, RecordScheduledTaskRun::SELF_RECORDING, true)) {
                $command = collect((new Finder)->files()->in(app_path('Console/Commands'))->name('*.php'))
                    ->first(fn ($f) => str_contains($f->getContents(), "'{$event->description}'") && str_contains($f->getContents(), 'recordSuccess'));
                $this->assertNotNull($command, "{$event->description} claims to record its own heartbeat");
            }
        }
    }

    #[Test]
    public function operations_status_covers_the_contracted_components(): void
    {
        $names = array_map(fn ($c) => $c->component, app(OperationalStatusService::class)->full());

        foreach (['database', 'redis', 'storage', 'queue:default', 'queue:integrations', 'queue:notifications', 'outbox', 'recovery', 'webhooks', 'communications', 'automation', 'ai_gateway',
            'scheduler:communication-deliveries-redispatch', 'scheduler:communications-publish-scheduled', 'scheduler:worker-canaries'] as $component) {
            $this->assertContains($component, $names);
        }
    }

    #[Test]
    public function observability_code_never_enters_tenant_scope_or_reads_audit_or_analytics(): void
    {
        $paths = [app_path('Support/Observability'), app_path('Http/Middleware/RecordHttpMetrics.php'), app_path('Jobs/WorkerCanaryJob.php'), app_path('Listeners/RecordScheduledTaskRun.php'), app_path('Listeners/RecordQueueHeartbeat.php'), base_path('metrics/index.php')];

        foreach ($paths as $path) {
            $files = is_dir($path) ? iterator_to_array((new Finder)->files()->in($path)->name('*.php')) : [new \SplFileInfo($path)];
            foreach ($files as $file) {
                $source = (string) file_get_contents($file->getPathname());
                foreach (['withSchool(', 'use App\\Support\\Tenancy\\TenantContext;', 'App\\Domain\\Analytics', 'GroupSafeReport', 'school_audit_events', 'platform_audit_events', 'SchoolAuditEvent', 'BYPASSRLS', 'ServiceIdentity'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $source, basename($file->getPathname())." must not use {$forbidden}");
                }
            }
        }
    }

    #[Test]
    public function the_scrape_credential_is_an_o4_secret_not_a_service_identity(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('deploy/processes.json')), true);

        $this->assertContains('METRICS_SCRAPE_TOKEN', $manifest['secret_groups']['app_runtime']);
        preg_match('/^METRICS_SCRAPE_TOKEN=(.*)$/m', (string) file_get_contents(base_path('.env.example')), $committed);
        $this->assertSame('', trim($committed[1] ?? 'missing'), 'no committed scrape token value');
        foreach (glob(app_path('Support/ServiceIdentities/*.php')) ?: [] as $file) {
            $this->assertStringNotContainsString('metrics', strtolower((string) file_get_contents($file)), basename($file));
        }
    }
}
