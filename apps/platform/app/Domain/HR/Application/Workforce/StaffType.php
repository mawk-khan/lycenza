<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A: the CLOSED staff classification of an employment's terms
 * (`employment_records_staff_type_check`, `employment_terms_staff_type_check`).
 * Distinct from the School-configured Employee Category, the Department, the
 * Position (designation) and any application role: none of them implies a
 * staff type, and a staff type grants nothing. A further value is a reviewed
 * change (this enum, both CHECKs and their tests), never user input.
 */
enum StaffType: string
{
    case TEACHING = 'teaching';
    case NON_TEACHING = 'non_teaching';
    case OUTSOURCED = 'outsourced';

    public function label(): string
    {
        return match ($this) {
            self::TEACHING => 'Teaching',
            self::NON_TEACHING => 'Non-teaching',
            self::OUTSOURCED => 'Outsourced',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
