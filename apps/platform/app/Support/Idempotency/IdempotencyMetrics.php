<?php

namespace App\Support\Idempotency;

use App\Support\Observability\MetricsRecorder;

/**
 * Section 29 / Phase 0O.5A: emits `lycenza_idempotency_requests_total{outcome}`
 * (formerly the `idempotency_<outcome>_total` log line, now a real counter
 * in the catalog -- ADR 0051 §10) via the shared
 * App\Support\Observability\MetricsRecorder abstraction (Phase 0C.4)
 * -- this class predates that shared abstraction (Phase 0C.2 built it
 * first, as the original vendor-neutral-metrics precedent) and is kept
 * as a thin, call-site-stable wrapper rather than being deleted, so
 * every existing call site (`App\Http\Middleware\EnsureIdempotent`)
 * needs no change.
 */
class IdempotencyMetrics
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function increment(IdempotencyOutcome $outcome): void
    {
        $this->metrics->counter('lycenza_idempotency_requests_total', 1, ['outcome' => $outcome->value]);
    }
}
