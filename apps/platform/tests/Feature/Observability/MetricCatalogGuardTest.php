<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\Logging\StructuredJsonFormatter;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\QueueName;
use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §3.5, §10): the metric privacy and cardinality
 * guard. Every metric's labels come from the closed LABELS set, every label
 * value from a closed list; no label may name a School, person, record,
 * request, client, delivery/event, URL or IP.
 */
class MetricCatalogGuardTest extends TestCase
{
    /** Whole label words that would identify a School, person, record or request. */
    private const FORBIDDEN_LABEL_WORDS = ['school', 'user', 'student', 'employee', 'guardian', 'member', 'membership', 'correlation', 'email', 'client', 'record', 'url', 'host', 'ip', 'actor', 'tenant', 'key', 'id', 'trace', 'session', 'token'];

    #[Test]
    public function every_label_is_in_the_closed_vocabulary_and_none_identifies_anyone(): void
    {
        foreach (MetricCatalog::LABELS as $label) {
            $this->assertSame([], array_intersect(explode('_', $label), self::FORBIDDEN_LABEL_WORDS), "label {$label}");
            $this->assertNotSame('request_id', $label);
        }

        foreach (MetricCatalog::definitions() as $name => $definition) {
            $this->assertMatchesRegularExpression('/^lycenza_[a-z_]+$/', $name);
            if ($definition['type'] === 'counter') {
                $this->assertStringEndsWith('_total', $name);
            }
            foreach ($definition['labels'] as $label => $values) {
                $this->assertContains($label, MetricCatalog::LABELS, "{$name}.{$label}");
                $this->assertNotEmpty($values);
                // `scheduled_task` is pinned to the code-defined schedule
                // (the_scheduled_task_vocabulary_is_the_live_schedule), one
                // series per task, so it gets its own ceiling (E21.2C).
                $this->assertLessThanOrEqual($label === 'scheduled_task' ? 30 : 20, count($values), "{$name}.{$label} must stay a small closed set");
                foreach ($values as $value) {
                    $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $value);
                }
            }
        }
    }

    #[Test]
    public function log_context_identifiers_can_never_become_metric_labels(): void
    {
        $identifiers = ['school_id', 'actor_user_id', 'request_id', 'correlation_id', 'trace_id', 'elevation_id', 'api_client_id'];

        $this->assertSame([], array_intersect($identifiers, MetricCatalog::LABELS));
        $this->assertSame($identifiers, array_values(array_intersect($identifiers, StructuredJsonFormatter::FIELDS)), 'logs may carry them; metrics may not');

        $this->expectException(InvalidArgumentException::class);
        app(MetricsRecorder::class)->counter('lycenza_http_requests_total', 1, ['request_surface' => 'web', 'status_class' => '2xx', 'school_id' => 'x']);
    }

    #[Test]
    public function the_recorder_refuses_unknown_metrics_labels_and_values(): void
    {
        foreach ([
            ['lycenza_not_in_catalog_total', []],
            ['lycenza_http_requests_total', ['request_surface' => 'web']],
            ['lycenza_http_requests_total', ['request_surface' => '/api/v1/students/123', 'status_class' => '2xx']],
            ['lycenza_queue_jobs_failed_total', ['queue' => 'user-supplied']],
        ] as [$name, $labels]) {
            try {
                app(MetricsRecorder::class)->counter($name, 1, $labels);
                $this->fail("{$name} with ".json_encode($labels).' must be refused');
            } catch (InvalidArgumentException) {
                $this->assertSame([], app(MetricStore::class)->all());
            }
        }
    }

    #[Test]
    public function the_scheduled_task_vocabulary_is_the_live_schedule(): void
    {
        $live = array_map(fn ($event) => $event->description, app(Schedule::class)->events());
        sort($live);
        $catalog = MetricCatalog::scheduledTasks();
        sort($catalog);

        $this->assertSame($catalog, $live);
    }

    #[Test]
    public function the_queue_vocabulary_and_canaries_are_the_manifest_workers(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('deploy/processes.json')), true);
        $workers = array_values(array_filter(array_map(fn ($p) => $p['queue'] ?? null, $manifest['processes'])));
        sort($workers);

        $queues = MetricCatalog::queues();
        sort($queues);
        $this->assertSame($workers, $queues);
        $this->assertSame($queues, collect(QueueName::requiredWorkerQueues())->map->value->sort()->values()->all());
    }
}
