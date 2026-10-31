<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.7 (OD-SIS-4) / SIS 11.8 (OD-SIS-5A, OD-SIS-5B): the CLOSED registry
 * of Student community types -- exactly House, Club and Activity. A further
 * type (Project, Scholarship cohort, custom group, tag, segment, ...) is a
 * reviewed change -- this enum, the `student_groups_type_check` and
 * `student_group_memberships_type_check` constraints, and their tests --
 * never a value a user can enter.
 *
 * - HOUSE: one current House per Student (SIS 11.7 contract, unchanged).
 * - CLUB / ACTIVITY ("communities"): any number at once, one current
 *   membership per Student PER Club/Activity.
 */
enum StudentGroupType: string
{
    case HOUSE = 'house';
    case CLUB = 'club';
    case ACTIVITY = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::HOUSE => 'House',
            self::CLUB => 'Club',
            self::ACTIVITY => 'Activity',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::HOUSE => 'Houses',
            self::CLUB => 'Clubs',
            self::ACTIVITY => 'Activities',
        };
    }

    /** A Club or Activity: several at once, per-group uniqueness. */
    public function isCommunity(): bool
    {
        return $this !== self::HOUSE;
    }

    /** @return list<self> */
    public static function communities(): array
    {
        return [self::CLUB, self::ACTIVITY];
    }
}
