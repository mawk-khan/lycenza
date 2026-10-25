<?php

namespace App\Domain\Communications\Application\Audience\FeeReminder;

use App\Support\Money\Money;

/**
 * ORG.9D-2 (ADR 0076 §74): one Student's CURRENT outstanding inside a fee
 * reminder's selection, exactly as the Outstanding fees page reports it --
 * always strictly positive (a settled or credit Student is never a row).
 */
final readonly class FeeReminderBalance
{
    public function __construct(
        public string $studentId,
        public Money $outstanding,
    ) {
        if (! $outstanding->isPositive()) {
            throw new \InvalidArgumentException('A fee reminder balance is strictly positive.');
        }
    }
}
