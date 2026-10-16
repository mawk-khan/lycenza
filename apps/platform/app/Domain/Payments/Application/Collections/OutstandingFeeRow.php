<?php

namespace App\Domain\Payments\Application\Collections;

use App\Support\Money\Money;

/** UAT-C3 (UAT-FIN-08): what one Student still owes, from authoritative charge state. */
final readonly class OutstandingFeeRow
{
    public function __construct(
        public string $studentId,
        public Money $outstanding,
        public Money $overdue,
        public int $openCharges,
        public ?string $earliestOverdueDueDate,
        public ?string $nextDueDate,
    ) {}
}
