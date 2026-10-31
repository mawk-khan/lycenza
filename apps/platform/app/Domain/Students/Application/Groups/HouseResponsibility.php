<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.7: the closed House responsibility catalogue -- DESCRIPTIVE Student
 * participation only. A responsibility is never a capability, role, scope or
 * access decision (pinned by StudentHouseArchitectureTest). Captain and Vice
 * Captain have one current holder per House (database partial unique
 * index); a House may have several Prefects.
 */
enum HouseResponsibility: string
{
    case HOUSE_CAPTAIN = 'house_captain';
    case VICE_CAPTAIN = 'vice_captain';
    case PREFECT = 'prefect';

    public function label(): string
    {
        return match ($this) {
            self::HOUSE_CAPTAIN => 'House Captain',
            self::VICE_CAPTAIN => 'Vice Captain',
            self::PREFECT => 'Prefect',
        };
    }

    /** One current holder per House. */
    public function isUnique(): bool
    {
        return $this !== self::PREFECT;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
