<?php

namespace App\Support\Observability\Alerts;

use Closure;

/**
 * Phase 0O.5A (ADR 0051 §14.4): one catalog alert -- id, human summary,
 * threshold source (A contract / B runtime cadence / C operator value),
 * runbook, and its severity tiers. Each tier carries the portable
 * PromQL expression (for the exported rule file) and the equivalent
 * deterministic PHP condition over a MetricSnapshot (for tests).
 */
final class AlertRule
{
    /**
     * @param  list<array{severity: Severity, expr: string, for: string, when: Closure(MetricSnapshot): bool, suffix: string}>  $tiers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly string $source,
        public readonly string $runbook,
        public readonly array $tiers,
        public readonly ?string $disabledReason = null,
    ) {}

    public function enabled(): bool
    {
        return $this->disabledReason === null;
    }

    /** The most severe firing tier, or null. */
    public function evaluate(MetricSnapshot $snapshot): ?Severity
    {
        if (! $this->enabled()) {
            return null;
        }

        $firing = null;
        foreach ($this->tiers as $tier) {
            if (($tier['when'])($snapshot) && ($firing === null || $tier['severity']->rank() < $firing->rank())) {
                $firing = $tier['severity'];
            }
        }

        return $firing;
    }

    public function alertName(Severity $severity, string $suffix = ''): string
    {
        return 'Lycenza'.str_replace('-', '', $this->id).ucfirst($severity->label()).$suffix;
    }
}
