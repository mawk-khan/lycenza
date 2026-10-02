<?php

namespace App\Domain\Finance\Application\Periods;

/**
 * E21.3A: what a close of one period would face right now. Blockers refuse
 * the close; notices are shown but do not block. Both are machine codes.
 */
final class FinancialPeriodCloseEvaluation
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $notices
     */
    public function __construct(
        public readonly FinancialPeriodSummary $period,
        public readonly string $localToday,
        public readonly int $entryCount,
        public readonly array $blockers,
        public readonly array $notices,
    ) {}

    public function canClose(): bool
    {
        return $this->blockers === [];
    }
}
