<?php

namespace App\Domain\HR\Application\Profile;

/** HR.2A: why a reference code is retired (`employee_reference_codes_retire_check`). Closed; no free text. */
enum ReferenceCodeRetireReason: string
{
    case RECORDED_IN_ERROR = 'recorded_in_error';
    case REPLACED = 'replaced';
    case NO_LONGER_USED = 'no_longer_used';

    public function label(): string
    {
        return match ($this) {
            self::RECORDED_IN_ERROR => 'Recorded in error',
            self::REPLACED => 'Replaced by a new code',
            self::NO_LONGER_USED => 'No longer used',
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
