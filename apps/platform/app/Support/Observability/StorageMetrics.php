<?php

namespace App\Support\Observability;

/**
 * Phase 0O.5A (ADR 0051 §11): an object-storage operation that failed, by
 * operation only -- never the bucket, key, filename or School. Object
 * storage is not a readiness dependency (rule 56); these counts are what
 * alert OBS-24 watches.
 */
final class StorageMetrics
{
    public static function failed(string $operation): void
    {
        app(MetricsRecorder::class)->counter('lycenza_storage_operation_failures_total', 1, ['operation' => $operation]);
    }
}
