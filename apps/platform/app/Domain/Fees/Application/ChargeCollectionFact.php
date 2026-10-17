<?php

namespace App\Domain\Fees\Application;

/**
 * UAT-C3 (UAT-FIN-08): one uncancelled charge as the collections list needs
 * it -- who owes it, for which year, how much and when it is due. Amount is
 * the exact decimal string; never a float.
 */
final readonly class ChargeCollectionFact
{
    public function __construct(
        public string $id,
        public string $studentId,
        public string $academicYearId,
        public string $amount,
        public string $currency,
        public ?string $dueDate,
        // LC-4 (SB-03b): the School-local date the charge was assessed --
        // which placement it belongs to when a Student moved Campus mid-year.
        public ?string $assessedOn = null,
    ) {}
}
