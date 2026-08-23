<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 45: a provider-independent metrics interface.
 * `App\Support\Idempotency\IdempotencyMetrics` (Phase 0C.2) already
 * established the "structured log line, not a vendor SDK" pattern for
 * exactly this reason -- this interface generalizes it into one shared
 * abstraction instead of every subsystem reinventing its own metrics
 * helper (section 4: "do not create duplicate abstractions").
 *
 * Deliberately excludes any identifier that could become
 * high-cardinality (request id, correlation id, event id, delivery id,
 * user id, student id, a full URL) from `$labels` -- those belong in
 * logs/traces (section 47), never a metric label. School id is
 * likewise NEVER passed as a label by any current call site (section
 * 46) -- it is a legitimate cardinality concern (thousands of Schools)
 * even though it is not a *request-scoped* identifier the way the
 * others are; School-level metrics filtering is a logs/traces concern,
 * not this interface's.
 */
interface MetricsRecorder
{
    /**
     * @param  array<string, string>  $labels
     */
    public function counter(string $name, int $value = 1, array $labels = []): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function timing(string $name, float $milliseconds, array $labels = []): void;
}
