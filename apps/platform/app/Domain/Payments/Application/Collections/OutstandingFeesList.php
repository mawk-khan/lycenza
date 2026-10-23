<?php

namespace App\Domain\Payments\Application\Collections;

use App\Support\Money\Money;

/**
 * UAT-C3 (UAT-FIN-08): every Student matching the filters who still owes
 * money, ordered for follow-up (most overdue first), with the filtered
 * financial totals. Amounts only -- no headcount analytics (V11.1).
 *
 * ORG.9B (ADR 0076 §68): the totals are FILTERED totals -- every listed
 * row, never one page -- and add billed, concession and paid. `asAt` is
 * the School-local date the figures are as at (null: now); overdue is
 * judged against it.
 */
final readonly class OutstandingFeesList
{
    /** @param  list<OutstandingFeeRow>  $rows */
    public function __construct(
        public array $rows,
        public Money $totalOutstanding,
        public Money $totalOverdue,
        public string $schoolToday,
        public Money $totalBilled,
        public Money $totalConcession,
        public Money $totalPaid,
        public ?string $asAt = null,
    ) {}
}
