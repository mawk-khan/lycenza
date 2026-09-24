<?php

namespace App\Domain\Automation\Application;

use App\Models\DomainEventOutbox;

/**
 * Loop protection (ADR 0043 §8, depth 1). An event emitted while an
 * Automation action runs must carry `metadata[automationExecutionId]`
 * (plus the outbox's own `causation_id`/`correlation_id`); the Automation
 * consumer ignores any event so marked, so no rule can trigger itself or
 * another rule. v1's only action writes Automation's own review item and
 * emits no event, so nothing produces the marker yet -- the consumer-side
 * rejection is in place for the first action that does.
 */
final class AutomationOrigin
{
    public const METADATA_KEY = 'automationExecutionId';

    /** @return array<string, string> */
    public static function metadataFor(string $executionId): array
    {
        return [self::METADATA_KEY => $executionId];
    }

    public static function isAutomationOriginated(DomainEventOutbox $event): bool
    {
        return isset($event->metadata[self::METADATA_KEY]);
    }
}
