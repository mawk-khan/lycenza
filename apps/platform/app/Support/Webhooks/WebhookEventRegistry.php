<?php

namespace App\Support\Webhooks;

/**
 * The closed catalog of domain event types that MAY become a webhook
 * subscription (Phase 0C.3 section 12). An internal domain event
 * existing in `domain_event_outbox` does not automatically become
 * externally publishable -- only event types registered here, with
 * `externallyVisible: true`, may be subscribed to.
 *
 * Deliberately minimal, like App\Support\Settings\SettingRegistry: no
 * speculative business-event entries (root CLAUDE.md rule 2). Two
 * entries exist:
 *
 * - `school.setting.changed.v1`: the same Phase 0C demonstration event
 *   proven end to end in Proof B before this checkpoint (its payload,
 *   {key, value}, was already minimized at event-creation time -- see
 *   App\Domain\Platform\Events\SchoolSettingChanged).
 * - `platform.webhook_test.v1`: a synthetic, local/testing-only event
 *   (section 78) that exists purely so this subsystem's live proof
 *   doesn't need a real ERP business event to exercise end to end.
 */
class WebhookEventRegistry
{
    /**
     * @var array<string, array{version: int, externallyVisible: bool}>
     */
    private const CATALOG = [
        'school.setting.changed.v1' => ['version' => 1, 'externallyVisible' => true],
        'platform.webhook_test.v1' => ['version' => 1, 'externallyVisible' => true],
    ];

    public function isSubscribable(string $eventType): bool
    {
        return (self::CATALOG[$eventType]['externallyVisible'] ?? false) === true;
    }

    public function exists(string $eventType): bool
    {
        return array_key_exists($eventType, self::CATALOG);
    }

    /**
     * @return array<int, string>
     */
    public function subscribableEventTypes(): array
    {
        return array_keys(array_filter(self::CATALOG, fn (array $entry) => $entry['externallyVisible']));
    }
}
