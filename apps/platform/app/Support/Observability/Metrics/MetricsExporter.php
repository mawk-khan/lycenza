<?php

namespace App\Support\Observability\Metrics;

use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Observability\Signals\OperationalSignals;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §9-§11): renders the Prometheus text exposition
 * (0.0.4) for the private metrics listener.
 *
 * - Scrape-time state comes from OperationalSignals (the same readers
 *   operations status uses): heartbeats, queue driver counters, outbox,
 *   failed_jobs, operational_work_backlog. Platform-scoped, bounded
 *   queries only -- no School context, no School walk, no tenant table, no
 *   audit ledger.
 * - Event counters, histograms and stored gauges come from the shared
 *   MetricStore.
 * - Deployment evidence comes from DeploymentEvidence.
 *
 * Each collector is isolated: one that fails is omitted from this scrape
 * and counted in `lycenza_metrics_collection_errors_total{component}`;
 * the rest of the scrape still renders. Every series is checked against
 * MetricCatalog before it is written -- nothing outside the catalog can
 * leave this class.
 */
final class MetricsExporter
{
    /** @var array<string, array<string, float>> name => [series key => value] */
    private array $samples = [];

    public function __construct(
        private readonly MetricStore $store,
        private readonly MetricsRecorder $recorder,
        private readonly OperationalSignals $signals,
        private readonly OperationalStatusService $status,
        private readonly DeploymentEvidence $evidence,
    ) {}

    public function render(): string
    {
        $this->samples = [];

        $databaseUp = true;
        $this->collect('readiness', function () use (&$databaseUp): void {
            $databaseUp = $this->status->database()->status === OperationalStatus::Healthy;
            $this->gauge('lycenza_readiness_status', ['dependency' => 'postgresql'], $databaseUp ? 1 : 0);
            $this->gauge('lycenza_readiness_status', ['dependency' => 'redis'], $this->status->redis()->status === OperationalStatus::Healthy ? 1 : 0);
        });

        // The optional AI Gateway is probed only where the deployment runs one
        // (a service token is configured); "not ready" is a 0, not a
        // collection failure.
        $this->collect('ai_gateway', function (): void {
            $base = rtrim((string) config('services.ai_gateway.base_url'), '/');
            if ($base === '' || trim((string) config('services.ai_gateway.service_token')) === '') {
                return;
            }
            try {
                $ok = Http::timeout(max(0.1, (int) config('observability.ai_gateway_check_timeout_ms') / 1000))->get($base.'/health/ready')->successful();
            } catch (Throwable) {
                $ok = false;
            }
            $this->gauge('lycenza_ai_gateway_ready', [], $ok ? 1 : 0);
        });

        // With PostgreSQL unreachable the readiness gauge above IS the signal:
        // the database-backed collectors are skipped rather than each waiting
        // out a connect timeout.
        if (! $databaseUp) {
            $this->collect('evidence', fn () => $this->evidenceSamples());
            $this->collect('recorder', fn () => $this->storedSamples());

            return $this->exposition();
        }

        $this->collect('heartbeats', function (): void {
            foreach ($this->signals->taskHeartbeats() as $task) {
                if ($task->lastSuccessAt !== null) {
                    $this->gauge('lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => $task->name], $task->lastSuccessAt->getTimestamp());
                }
            }
            foreach ($this->signals->recoverySweeps() as $source => $at) {
                if ($at !== null) {
                    $this->gauge('lycenza_reconciliation_last_success_timestamp_seconds', ['recovery_source' => $source], $at->getTimestamp());
                }
            }
        });

        $this->collect('queues', function (): void {
            foreach ($this->signals->queues() as $queue) {
                $labels = ['queue' => $queue->queue];
                $this->gauge('lycenza_queue_pending_jobs', $labels, $queue->pending);
                $this->gauge('lycenza_queue_delayed_jobs', $labels, $queue->delayed);
                $this->gauge('lycenza_queue_reserved_jobs', $labels, $queue->reserved);
                $this->gauge('lycenza_queue_oldest_pending_age_seconds', $labels, $queue->oldestPendingAgeSeconds);
                if ($queue->heartbeatAt !== null) {
                    $this->gauge('lycenza_queue_heartbeat_last_success_timestamp_seconds', $labels, $queue->heartbeatAt->getTimestamp());
                }
            }
        });

        $this->collect('failed_jobs', function (): void {
            foreach ($this->signals->failedJobs() as $queue => $count) {
                $this->gauge('lycenza_failed_jobs', ['queue' => $queue], $count);
            }
        });

        $this->collect('outbox', function (): void {
            $outbox = $this->signals->outbox();
            $this->gauge('lycenza_outbox_pending_events', [], $outbox->pending);
            $this->gauge('lycenza_outbox_oldest_pending_age_seconds', [], $outbox->oldestPendingAgeSeconds);
            $this->gauge('lycenza_outbox_unacknowledged_dispatched_events', [], $outbox->unacknowledged);
            $this->gauge('lycenza_outbox_stale_events', [], $outbox->stale);
            $this->gauge('lycenza_outbox_failed_events', [], $outbox->failed);
        });

        $this->collect('backlog', function (): void {
            $webhook = $this->signals->backlog('webhook');
            foreach (['pending', 'retrying', 'delivering'] as $state) {
                $this->gauge('lycenza_webhook_deliveries', ['state' => $state], $webhook->states[$state] ?? 0);
            }
            $this->gauge('lycenza_webhook_overdue_deliveries', [], $webhook->overdue);
            $this->gauge('lycenza_webhook_oldest_overdue_age_seconds', [], $webhook->oldestOverdueAgeSeconds);

            $communication = $this->signals->backlog('communication');
            $deferred = $this->signals->deferredCommunications();
            $this->gauge('lycenza_communication_deliveries', ['state' => 'pending'], $communication->states['pending'] ?? 0);
            $this->gauge('lycenza_communication_deliveries', ['state' => 'deferred'], $deferred);
            $this->gauge('lycenza_communication_deliveries', ['state' => 'queued_due'], max(0, ($communication->states['queued'] ?? 0) - $deferred));
            $this->gauge('lycenza_communication_deliveries', ['state' => 'sending'], $communication->states['sending'] ?? 0);
            $this->gauge('lycenza_communication_overdue_deliveries', [], $communication->overdue);
            $this->gauge('lycenza_communication_oldest_overdue_age_seconds', [], $communication->oldestOverdueAgeSeconds);

            $automation = $this->signals->backlog('automation');
            foreach (['pending', 'running'] as $state) {
                $this->gauge('lycenza_automation_pending_executions', ['state' => $state], $automation->states[$state] ?? 0);
            }
            $this->gauge('lycenza_automation_overdue_executions', [], $automation->overdue);
            $this->gauge('lycenza_automation_oldest_overdue_age_seconds', [], $automation->oldestOverdueAgeSeconds);
        });

        $this->collect('evidence', fn () => $this->evidenceSamples());

        // Last, so this scrape's own collection errors are included.
        $this->collect('recorder', fn () => $this->storedSamples());

        return $this->exposition();
    }

    private function evidenceSamples(): void
    {
        foreach ($this->evidence->samples() ?? [] as [$name, $labels, $value]) {
            $this->gauge($name, $labels, $value);
        }
    }

    private function storedSamples(): void
    {
        foreach ($this->store->all() as $key => $value) {
            [$name, $labels] = Series::parse($key);
            $this->stored($name, $labels, $value);
        }
    }

    private function collect(string $component, callable $collector): void
    {
        try {
            $collector();
        } catch (Throwable) {
            $this->recorder->counter('lycenza_metrics_collection_errors_total', 1, ['component' => $component]);
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function gauge(string $name, array $labels, int|float|null $value): void
    {
        if ($value === null || (MetricCatalog::definitions()[$name]['type'] ?? null) !== 'gauge' || ! MetricCatalog::accepts($name, $labels)) {
            return;
        }

        $this->samples[$name][Series::key($name, $labels)] = (float) $value;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function stored(string $series, array $labels, float $value): void
    {
        foreach (['_bucket', '_sum', '_count'] as $suffix) {
            if (str_ends_with($series, $suffix) && isset(MetricCatalog::definitions()[substr($series, 0, -strlen($suffix))])) {
                $base = substr($series, 0, -strlen($suffix));
                $bucketless = $labels;
                unset($bucketless['le']);
                if (MetricCatalog::definitions()[$base]['type'] === 'histogram' && MetricCatalog::accepts($base, $bucketless)
                    && ($suffix === '_bucket') === isset($labels['le'])) {
                    $this->samples[$base][Series::key($series, $labels)] = $value;
                }

                return;
            }
        }

        if (isset(MetricCatalog::definitions()[$series]) && MetricCatalog::definitions()[$series]['type'] !== 'histogram' && MetricCatalog::accepts($series, $labels)) {
            $this->samples[$series][Series::key($series, $labels)] = $value;
        }
    }

    private function exposition(): string
    {
        $lines = [];

        foreach (MetricCatalog::definitions() as $name => $definition) {
            if (! isset($this->samples[$name])) {
                continue;
            }

            $lines[] = "# HELP {$name} ".$definition['help'];
            $lines[] = "# TYPE {$name} ".$definition['type'];

            $series = $this->samples[$name];

            if ($definition['type'] === 'histogram') {
                array_push($lines, ...$this->histogramLines($name, $series));

                continue;
            }

            ksort($series);
            foreach ($series as $key => $value) {
                [$sampleName, $labels] = Series::parse($key);
                $lines[] = $sampleName.$this->labels($labels).' '.$this->number($value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One histogram per label set: every bucket in ascending order (absent
     * ones as their cumulative value), `+Inf` last, then `_sum` and `_count`
     * -- the order the exposition format requires.
     *
     * @param  array<string, float>  $series
     * @return list<string>
     */
    private function histogramLines(string $name, array $series): array
    {
        $groups = [];
        foreach ($series as $key => $value) {
            [$sampleName, $labels] = Series::parse($key);
            $le = $labels['le'] ?? null;
            unset($labels['le']);
            $group = Series::key('', $labels);
            $groups[$group]['labels'] = $labels;
            if ($le !== null) {
                $groups[$group]['buckets'][$le] = $value;
            } else {
                $groups[$group][substr($sampleName, strlen($name) + 1)] = $value;
            }
        }
        ksort($groups);

        $lines = [];
        foreach ($groups as $group) {
            $running = 0.0;
            foreach ([...MetricCatalog::HISTOGRAM_BUCKETS, INF] as $bound) {
                $le = $bound === INF ? '+Inf' : (string) $bound;
                $running = max($running, $group['buckets'][$le] ?? 0.0);
                $lines[] = $name.'_bucket'.$this->labels([...$group['labels'], 'le' => $le]).' '.$this->number($running);
            }
            $lines[] = $name.'_sum'.$this->labels($group['labels']).' '.$this->number($group['sum'] ?? 0.0);
            $lines[] = $name.'_count'.$this->labels($group['labels']).' '.$this->number($group['count'] ?? $running);
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        return '{'.implode(',', array_map(fn ($k, $v) => $k.'="'.addcslashes($v, "\\\"\n").'"', array_keys($labels), $labels)).'}';
    }

    private function number(float $value): string
    {
        return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }
}
