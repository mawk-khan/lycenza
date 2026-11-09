<?php

namespace App\Domain\Leave\Application\Calendar;

/**
 * HR.R1-C22 (WO-5): the staff calendar's answer for one School-local date. Unknown is never a working
 * or an off day: before the School's history start the answer is NOT_RECORDED, and a School that has
 * never recorded a week is NOT_CONFIGURED.
 */
final readonly class CalendarDay
{
    public const RECORDED = 'recorded';

    public const NOT_RECORDED = 'not_recorded';

    public const NOT_CONFIGURED = 'not_configured';

    /** @param  ?array{id: string, name: string, portion: string}  $holiday */
    public function __construct(
        public string $date,
        public string $status,
        public ?string $weekPortion = null,
        public ?array $holiday = null,
        public ?string $versionId = null,
    ) {}

    public function recorded(): bool
    {
        return $this->status === self::RECORDED;
    }
}
