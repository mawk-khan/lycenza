<?php

namespace App\Domain\Payments\Application\Collections;

use App\Support\Money\Money;

/**
 * UAT-C3 (UAT-FIN-08): what one Student still owes, from authoritative charge state.
 *
 * ORG.9B (ADR 0076 §68): with the composition of that balance over the
 * Student's uncancelled Charges in the read's population --
 * `billed - concession - paid = outstanding`, exactly, per Charge and so
 * per Student (`ChargeState::outstanding()`):
 * - billed: the Charges' amounts;
 * - concession: their live fee adjustments (every fee adjustment is the
 *   posted effect of an approved concession, scholarship or waiver);
 * - paid: their effective payment allocation (allocations plus correction
 *   lines, so a reversal, refund or reallocation is already out of it).
 */
final readonly class OutstandingFeeRow
{
    public function __construct(
        public string $studentId,
        public Money $outstanding,
        public Money $overdue,
        public int $openCharges,
        public ?string $earliestOverdueDueDate,
        public ?string $nextDueDate,
        public Money $billed,
        public Money $concession,
        public Money $paid,
    ) {}
}
