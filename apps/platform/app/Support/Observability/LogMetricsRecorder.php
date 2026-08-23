<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;

/**
 * Default MetricsRecorder implementation (section 45): one structured
 * log line per emission, matching the exact `metric`/`value`/`type`
 * shape `App\Support\Idempotency\IdempotencyMetrics` established in
 * Phase 0C.2. No commercial telemetry vendor is bound here -- a future
 * metrics exporter (Prometheus, OTel metrics, ...) scrapes/ships these
 * structured lines, or a different MetricsRecorder implementation is
 * bound in the container, without any call site changing.
 */
class LogMetricsRecorder implements MetricsRecorder
{
    public function counter(string $name, int $value = 1, array $labels = []): void
    {
        $this->emit('counter', $name, $value, $labels);
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->emit('gauge', $name, $value, $labels);
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        $this->emit('timing', $name, $milliseconds, $labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function emit(string $type, string $name, int|float $value, array $labels): void
    {
        Log::info('metric', [
            'metric_type' => $type,
            'metric' => $name,
            'value' => $value,
            'labels' => $labels,
        ]);
    }
}
