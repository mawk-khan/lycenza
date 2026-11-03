<?php

namespace App\Domain\Leave\Application\Accrual;

use Carbon\CarbonImmutable;

/**
 * HR.10 (ADR 0065 §29.2): when a month's accrual becomes postable -- in
 * advance (its first day) or in arrears (the day after its last day). The
 * School chooses per version; no other convention exists.
 */
enum LeaveAccrualTiming: string
{
    case PERIOD_START = 'period_start';
    case PERIOD_END = 'period_end';

    public function label(): string
    {
        return match ($this) {
            self::PERIOD_START => 'At the start of the month (in advance)',
            self::PERIOD_END => 'After the month ends (in arrears)',
        };
    }

    /** The School-local date from which the month starting $periodStart may be credited. */
    public function postableOn(string $periodStart): string
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $periodStart);

        return $this === self::PERIOD_START ? $start->toDateString() : $start->addMonthNoOverflow()->toDateString();
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
