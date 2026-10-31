<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.8: the closed reasons of a Club membership or Activity
 * participation (no free text). Every one starts `joined`; an end records
 * withdrew, completed (Activities only), left_school, school_decision or
 * correction. House reasons stay HouseMembershipReason (the database checks
 * each type's set).
 */
enum CommunityMembershipReason: string
{
    case JOINED = 'joined';
    case WITHDREW = 'withdrew';
    case COMPLETED = 'completed';
    case LEFT_SCHOOL = 'left_school';
    case SCHOOL_DECISION = 'school_decision';
    case CORRECTION = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::JOINED => 'Joined',
            self::WITHDREW => 'Student withdrew',
            self::COMPLETED => 'Participation completed',
            self::LEFT_SCHOOL => 'Left the School',
            self::SCHOOL_DECISION => 'School decision',
            self::CORRECTION => 'Correction (added in error)',
        };
    }

    /** @return list<self> */
    public static function endReasons(StudentGroupType $type): array
    {
        return $type === StudentGroupType::ACTIVITY
            ? [self::COMPLETED, self::WITHDREW, self::SCHOOL_DECISION, self::LEFT_SCHOOL, self::CORRECTION]
            : [self::WITHDREW, self::SCHOOL_DECISION, self::LEFT_SCHOOL, self::CORRECTION];
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
