<?php

namespace App\Domain\HR\Application\Profile;

/**
 * HR.2A (S-16, OD-HR-13): why an address, emergency contact, note,
 * qualification, previous-experience or certification record is archived
 * (`<table>_archive_check`). Archiving replaces deletion: the row stays as
 * frozen evidence. Closed: each reason has a defined meaning; no free text.
 */
enum ProfileRecordArchiveReason: string
{
    case RECORDED_IN_ERROR = 'recorded_in_error';
    case NO_LONGER_CURRENT = 'no_longer_current';
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::RECORDED_IN_ERROR => 'Recorded in error',
            self::NO_LONGER_CURRENT => 'No longer current',
            self::SUPERSEDED => 'Superseded by a newer record',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
