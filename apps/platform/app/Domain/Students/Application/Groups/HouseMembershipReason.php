<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.7: the closed reasons of a House membership's start and end (no
 * free text). A first assignment starts with `assignment`; a change records
 * `reassignment` or `correction` on both rows; an explicit end records
 * `left_school`, `school_decision` or `correction`.
 */
enum HouseMembershipReason: string
{
    case ASSIGNMENT = 'assignment';
    case REASSIGNMENT = 'reassignment';
    case CORRECTION = 'correction';
    case LEFT_SCHOOL = 'left_school';
    case SCHOOL_DECISION = 'school_decision';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'Assigned',
            self::REASSIGNMENT => 'Moved to another House',
            self::CORRECTION => 'Correction (assigned in error)',
            self::LEFT_SCHOOL => 'Left the School',
            self::SCHOOL_DECISION => 'School decision',
        };
    }

    /** @return list<self> */
    public static function changeReasons(): array
    {
        return [self::REASSIGNMENT, self::CORRECTION];
    }

    /** @return list<self> */
    public static function endReasons(): array
    {
        return [self::SCHOOL_DECISION, self::LEFT_SCHOOL, self::CORRECTION];
    }

    /**
     * @param  list<self>  $reasons
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $reasons): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], $reasons);
    }
}
