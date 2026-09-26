<?php

namespace App\Support\Observability;

/**
 * Phase 0O.5A (ADR 0051 §11): one recovery sweep's outcome as metrics --
 * runs, rows (inspected / redispatched / acknowledged / failed) and
 * duration per recovery source (`outbox`, `webhook`, `communication`,
 * `automation`). No event, delivery or execution id ever becomes a label.
 * Freshness ("last success") comes from the durable task heartbeat.
 */
final class RecoveryMetrics
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    /**
     * @param  array{inspected?: int, redispatched?: int, acknowledged?: int, failed?: int}  $rows
     */
    public function record(string $source, bool $succeeded, float $startedAt, array $rows = []): void
    {
        $this->metrics->counter('lycenza_reconciliation_runs_total', 1, ['recovery_source' => $source, 'outcome' => $succeeded ? 'success' : 'failure']);
        $this->metrics->observe('lycenza_reconciliation_duration_seconds', microtime(true) - $startedAt, ['recovery_source' => $source]);

        foreach (['inspected', 'redispatched', 'acknowledged', 'failed'] as $result) {
            if (($rows[$result] ?? 0) > 0) {
                $this->metrics->counter('lycenza_reconciliation_rows_total', $rows[$result], ['recovery_source' => $source, 'result' => $result]);
            }
        }
    }
}
