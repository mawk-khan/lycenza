<?php

namespace App\Support\Retention;

use App\Support\Observability\MetricsRecorder;

/**
 * E21 retention counters: `lycenza_retention_rows_total{operation, outcome}`.
 * Closed categories and outcomes only, counts only, never an identifier.
 */
final class RetentionMetrics
{
    public const OUTCOMES = ['eligible', 'deleted', 'held', 'skipped', 'unresolved', 'error'];

    /** E21.2C maintenance categories that are not database retention functions. */
    public const COMMUNICATION_CONTENT = 'communication_content';

    public const COMMUNICATION_DELIVERY = 'communication_delivery';

    public const STORAGE_ORPHAN = 'storage_orphan';

    /** @return list<string> */
    public static function categories(): array
    {
        return array_merge(RetentionExpiry::categories(), [self::COMMUNICATION_CONTENT, self::COMMUNICATION_DELIVERY, self::STORAGE_ORPHAN]);
    }

    /**
     * @param  array<string, int>  $counts  outcome => count (`errors` maps to `error`)
     */
    public static function record(MetricsRecorder $metrics, string $category, array $counts): void
    {
        foreach ($counts as $outcome => $count) {
            $outcome = $outcome === 'errors' ? 'error' : $outcome;

            if ($count > 0 && in_array($outcome, self::OUTCOMES, true)) {
                $metrics->counter('lycenza_retention_rows_total', $count, ['operation' => $category, 'outcome' => $outcome]);
            }
        }
    }
}
