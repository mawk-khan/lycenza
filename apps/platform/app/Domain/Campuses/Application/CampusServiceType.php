<?php

namespace App\Domain\Campuses\Application;

/**
 * ORG.12C (ADR 0080 §8, OD-12.5): the closed, code-owned Campus service
 * catalogue -- what a Campus offers, for people reading its profile. A
 * School never invents a code; the `campus_services_code_check` constraint
 * lists exactly these values (CampusServiceArchitectureTest).
 *
 * Descriptive operational offerings only. Never a module entitlement,
 * capability, billing plan or authorization: offering `transport` does not
 * enable the Transport module, and enabling the module claims no Campus
 * offers transport.
 */
enum CampusServiceType: string
{
    case DAY_SCHOOL = 'day_school';
    case EARLY_YEARS = 'early_years';
    case BOARDING = 'boarding';
    case MEALS = 'meals';
    case TRANSPORT = 'transport';

    public function label(): string
    {
        return match ($this) {
            self::DAY_SCHOOL => 'Day school',
            self::EARLY_YEARS => 'Early years / daycare',
            self::BOARDING => 'Boarding',
            self::MEALS => 'Meals',
            self::TRANSPORT => 'Transport',
        };
    }

    /** @return list<array{code: string, label: string}> in catalogue order */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['code' => $type->value, 'label' => $type->label()], self::cases());
    }
}
