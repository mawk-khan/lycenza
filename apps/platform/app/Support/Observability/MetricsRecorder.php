<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 45, redefined in Phase 0O.5A (ADR 0051 §9-§10): the
 * application's one way to record an EVENT metric (counters and
 * histograms). Every name and label set must be in
 * App\Support\Observability\Metrics\MetricCatalog -- a closed catalog with
 * closed label values; never a School, user, person, record, request,
 * client or delivery identifier. State metrics (backlogs, ages,
 * heartbeats) are computed at scrape time, not recorded here.
 *
 * Best effort: recording never throws into business code in production,
 * never retries and never buffers.
 */
interface MetricsRecorder
{
    /**
     * @param  array<string, string>  $labels
     */
    public function counter(string $name, int|float $value = 1, array $labels = []): void;

    /**
     * Set a stored gauge (a value an operator process reports, e.g. the last
     * verification result). Scrape-time state is NOT recorded here.
     *
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;

    /**
     * Record one observation (in seconds) into a histogram.
     *
     * @param  array<string, string>  $labels
     */
    public function observe(string $name, float $seconds, array $labels = []): void;
}
