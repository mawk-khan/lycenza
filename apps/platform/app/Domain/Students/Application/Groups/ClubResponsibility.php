<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.8: the closed Club responsibility catalogue -- DESCRIPTIVE Student
 * participation only, exactly like HouseResponsibility: never a capability,
 * role, scope or access decision (StudentGroupArchitectureTest). President,
 * Vice President and Secretary have one current holder per Club (database
 * partial unique index); a Club may have several Member Representatives.
 * Activities have no responsibilities.
 */
enum ClubResponsibility: string
{
    case PRESIDENT = 'club_president';
    case VICE_PRESIDENT = 'club_vice_president';
    case SECRETARY = 'club_secretary';
    case MEMBER_REPRESENTATIVE = 'member_representative';

    public function label(): string
    {
        return match ($this) {
            self::PRESIDENT => 'President',
            self::VICE_PRESIDENT => 'Vice President',
            self::SECRETARY => 'Secretary',
            self::MEMBER_REPRESENTATIVE => 'Member Representative',
        };
    }

    /** One current holder per Club. */
    public function isUnique(): bool
    {
        return $this !== self::MEMBER_REPRESENTATIVE;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
