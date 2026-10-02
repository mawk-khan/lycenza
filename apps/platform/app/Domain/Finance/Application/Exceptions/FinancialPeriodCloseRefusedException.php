<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * E21.3A (ADR 0064 §4): a close was refused before anything was written.
 * The reasons are machine codes (never amounts or personal data). The
 * period stays open and nothing changed.
 */
class FinancialPeriodCloseRefusedException extends FinanceException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(409, 'FINANCIAL_PERIOD_CLOSE_REFUSED', 'This financial period cannot be closed: '.implode(', ', $reasons).'.');
    }
}
