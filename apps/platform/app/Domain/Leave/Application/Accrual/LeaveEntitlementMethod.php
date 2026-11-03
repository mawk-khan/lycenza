<?php

namespace App\Domain\Leave\Application\Accrual;

/**
 * HR.10 (ADR 0065 §29.2): how a policy version funds a balance -- two
 * EXCLUSIVE alternatives, never added together (`leave_policies_entitlement_check`,
 * `leave_funding_conflict`). A further method is a reviewed change, never
 * configuration.
 */
enum LeaveEntitlementMethod: string
{
    /** The existing annual allocation run (or an explicit allocation of exact units). */
    case ANNUAL = 'annual';
    /** One `accrual` credit per whole month the version is assigned and the employment spans. */
    case MONTHLY = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::ANNUAL => 'Annual allocation',
            self::MONTHLY => 'Monthly accrual',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
