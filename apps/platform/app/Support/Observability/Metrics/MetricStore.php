<?php

namespace App\Support\Observability\Metrics;

/**
 * Phase 0O.5A (ADR 0051 §9): where event counters live between scrapes.
 * Production: one shared Redis hash (every web, worker and scheduler
 * process increments it; one scrape sees all). Tests: in-process array.
 * A lost store only resets counters -- rate() semantics tolerate resets,
 * and the durable facts stay in PostgreSQL.
 */
interface MetricStore
{
    public function increment(string $series, float $by): void;

    public function set(string $series, float $value): void;

    /** @return array<string, float> series => value */
    public function all(): array;

    public function flush(): void;
}
