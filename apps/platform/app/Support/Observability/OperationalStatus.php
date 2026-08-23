<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4. The smallest correct set of states for a component or an
 * aggregate (section 51) -- not a paging/incident-severity model, just
 * "can this be trusted right now."
 */
enum OperationalStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Unknown = 'unknown';

    /**
     * Aggregates a set of component statuses into one overall status --
     * the worst one wins, in this fixed severity order. Used both by
     * readiness (a small, essential subset of components) and by the
     * internal diagnostics endpoint (every component).
     *
     * @param  array<int, self>  $statuses
     */
    public static function worstOf(array $statuses): self
    {
        $severity = [self::Unhealthy, self::Degraded, self::Unknown, self::Healthy];

        foreach ($severity as $candidate) {
            foreach ($statuses as $status) {
                if ($status === $candidate) {
                    return $candidate;
                }
            }
        }

        return self::Healthy;
    }
}
