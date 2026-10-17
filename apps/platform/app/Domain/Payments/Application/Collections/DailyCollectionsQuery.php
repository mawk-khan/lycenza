<?php

namespace App\Domain\Payments\Application\Collections;

/**
 * LC-4 (M4): what the collections list is narrowed to. `from`/`to` are
 * School-local calendar dates (Y-m-d, inclusive); `receipt` matches part
 * of a receipt number or a provider reference exactly; `method` is a
 * manual payment method or `provider`; `studentIds` (null = everyone)
 * keeps Payments applied to those Students' charges.
 */
final readonly class DailyCollectionsQuery
{
    /** @param  list<string>|null  $studentIds */
    public function __construct(
        public string $from,
        public string $to,
        public ?string $receipt = null,
        public ?string $method = null,
        public ?array $studentIds = null,
        public int $page = 1,
    ) {}
}
