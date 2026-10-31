<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.7 (OD-SIS-4): the CLOSED registry of Student community types. Only
 * HOUSE is enabled. A further type (Club, Activity, Project, ...) is a
 * reviewed change -- this enum, the `student_groups_type_check` and
 * `student_group_memberships_type_check` constraints, and its tests --
 * never a value a user can enter.
 */
enum StudentGroupType: string
{
    case HOUSE = 'house';

    public function label(): string
    {
        return match ($this) {
            self::HOUSE => 'House',
        };
    }
}
