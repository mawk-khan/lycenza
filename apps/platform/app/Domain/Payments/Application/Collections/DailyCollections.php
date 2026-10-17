<?php

namespace App\Domain\Payments\Application\Collections;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * LC-4 (M4): one page of receipts plus totals across EVERY matching
 * receipt (not just this page), one entry per currency.
 */
final readonly class DailyCollections
{
    /**
     * @param  LengthAwarePaginator<int, DailyCollectionRow>  $rows
     * @param  list<array{currency: string, receipts: int, gross: string, retained: string}>  $totals
     */
    public function __construct(
        public LengthAwarePaginator $rows,
        public array $totals,
    ) {}
}
