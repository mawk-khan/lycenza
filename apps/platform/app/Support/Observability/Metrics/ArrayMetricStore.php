<?php

namespace App\Support\Observability\Metrics;

/** In-process store: tests (METRICS_STORE=array, forced by phpunit.xml). */
final class ArrayMetricStore implements MetricStore
{
    /** @var array<string, float> */
    private array $values = [];

    public function increment(string $series, float $by): void
    {
        $this->values[$series] = ($this->values[$series] ?? 0.0) + $by;
    }

    public function set(string $series, float $value): void
    {
        $this->values[$series] = $value;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function flush(): void
    {
        $this->values = [];
    }
}
