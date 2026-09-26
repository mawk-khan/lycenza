<?php

namespace App\Support\Observability\Metrics;

/** Metrics disabled (METRICS_STORE=null). */
final class NullMetricStore implements MetricStore
{
    public function increment(string $series, float $by): void {}

    public function set(string $series, float $value): void {}

    public function all(): array
    {
        return [];
    }

    public function flush(): void {}
}
