<?php

namespace App\Support\Observability\Metrics;

use App\Support\Observability\MetricsRecorder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §10): the MetricsRecorder. Validates every name
 * and label set against MetricCatalog, then writes to the MetricStore.
 *
 * - Outside the catalog: refused. In local/testing it THROWS (so a bad
 *   label is caught by the test suite); in production it is dropped and
 *   counted.
 * - A store failure is swallowed: the business request/job/transaction
 *   continues untouched, nothing is retried or buffered. The failure
 *   increments `lycenza_metrics_collection_errors_total{component="recorder"}`
 *   (itself best effort, never reported recursively) and is logged at
 *   most once a minute per process.
 */
final class StoreMetricsRecorder implements MetricsRecorder
{
    private static ?int $lastFailureLoggedAt = null;

    public function __construct(private readonly MetricStore $store) {}

    public function counter(string $name, int|float $value = 1, array $labels = []): void
    {
        if (! $this->valid($name, $labels, 'counter')) {
            return;
        }

        $this->write(fn () => $this->store->increment(Series::key($name, $labels), (float) $value));
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        if (! $this->valid($name, $labels, 'gauge')) {
            return;
        }

        $this->write(fn () => $this->store->set(Series::key($name, $labels), $value));
    }

    public function observe(string $name, float $seconds, array $labels = []): void
    {
        if (! $this->valid($name, $labels, 'histogram')) {
            return;
        }

        $seconds = max(0.0, $seconds);

        $this->write(function () use ($name, $seconds, $labels): void {
            foreach ([...MetricCatalog::HISTOGRAM_BUCKETS, INF] as $bound) {
                if ($seconds <= $bound) {
                    $this->store->increment(Series::key($name.'_bucket', [...$labels, 'le' => $bound === INF ? '+Inf' : (string) $bound]), 1);
                }
            }
            $this->store->increment(Series::key($name.'_sum', $labels), $seconds);
            $this->store->increment(Series::key($name.'_count', $labels), 1);
        });
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function valid(string $name, array $labels, string $type): bool
    {
        $definition = MetricCatalog::definitions()[$name] ?? null;

        if ($definition !== null && $definition['type'] === $type && MetricCatalog::accepts($name, $labels)) {
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            throw new InvalidArgumentException("Metric {$name} with labels [".implode(',', array_keys($labels)).'] is not in MetricCatalog.');
        }

        $this->failed();

        return false;
    }

    private function write(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            $this->failed();
        }
    }

    private function failed(): void
    {
        try {
            $this->store->increment(Series::key('lycenza_metrics_collection_errors_total', ['component' => 'recorder']), 1);
        } catch (Throwable) {
            // the store itself is unavailable: nothing further, never recursive
        }

        $now = time();
        if (self::$lastFailureLoggedAt === null || $now - self::$lastFailureLoggedAt >= 60) {
            self::$lastFailureLoggedAt = $now;
            try {
                Log::warning('observability.metrics.write_failed', ['component' => 'recorder']);
            } catch (Throwable) {
                // logging must not fail business work either
            }
        }
    }
}
