<?php

namespace App\Domain\Automation\Application\Catalog;

/**
 * The closed list of rule types (ADR 0043 §1). Nothing outside this list
 * can be enabled or executed; a new rule type is a code change reviewed
 * like any other.
 */
class AutomationRuleCatalog
{
    /** @var list<class-string<AutomationRuleType>> */
    public const RULE_TYPES = [
        AcademicYearSetupReviewRule::class,
    ];

    /** @return list<AutomationRuleType> */
    public function all(): array
    {
        return array_map(fn (string $class): AutomationRuleType => new $class, self::RULE_TYPES);
    }

    public function find(string $key): ?AutomationRuleType
    {
        foreach ($this->all() as $type) {
            if ($type->key() === $key) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<AutomationRuleType> */
    public function forEventType(string $eventType): array
    {
        return array_values(array_filter($this->all(), fn (AutomationRuleType $type) => $type->triggerEventType() === $eventType));
    }

    public function handlesEventType(string $eventType): bool
    {
        return $this->forEventType($eventType) !== [];
    }
}
