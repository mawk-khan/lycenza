<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12: why an APPROVED separation is cancelled before it is finalized
 * (`employment_separation_cases_cancel_reason_check`). Closed, no free text.
 * `superseded`: the employment ends another way (e.g. the deceased path).
 */
enum SeparationCancelReason: string
{
    case RESIGNATION_RETRACTED = 'resignation_retracted';
    case RECORDED_IN_ERROR = 'recorded_in_error';
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::RESIGNATION_RETRACTED => 'Resignation retracted and accepted',
            self::RECORDED_IN_ERROR => 'Recorded in error',
            self::SUPERSEDED => 'Employment ends another way',
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
