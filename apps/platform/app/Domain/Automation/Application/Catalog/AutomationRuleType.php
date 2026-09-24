<?php

namespace App\Domain\Automation\Application\Catalog;

use App\Models\DomainEventOutbox;

/**
 * One code-registered rule type (ADR 0043 §1): exactly one trigger, one
 * fixed condition, one action of a declared tier, and the capabilities the
 * owner must hold for the action. Schools cannot author these; they only
 * enable an instance of a registered type (AutomationRuleCatalog).
 */
interface AutomationRuleType
{
    /** Stable key stored on rule instances -- treat like a column name. */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** The one outbox event type that triggers this rule. */
    public function triggerEventType(): string;

    /** ADR 0043 §3 tier. Only tier 0 (informational) exists in v1. */
    public function tier(): int;

    /**
     * Capabilities the accountable owner must hold, re-verified before every
     * execution (ADR 0043 §5). Always includes `automation.manage`.
     *
     * @return list<string>
     */
    public function requiredCapabilities(): array;

    /** Flood guard (ADR 0043 §8): executions per School per 24 hours. */
    public function maxExecutionsPerDay(): int;

    /** The review item type this rule's tier 0 action creates. */
    public function reviewItemType(): string;

    /**
     * The fixed condition: the source reference this occurrence concerns, or
     * null when the event does not qualify. Reads identifiers only.
     *
     * @return array{type: string, id: string}|null
     */
    public function subjectFor(DomainEventOutbox $event): ?array;
}
