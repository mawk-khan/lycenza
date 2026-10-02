<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * E21.3A (ADR 0064 §6): the dual-read check inside the close transaction
 * found the carried-forward state different from the all-history state.
 * The close is rolled back, so the period stays open and no baseline is
 * kept. This is a defect to investigate, never something to override.
 */
class FinancialPeriodVerificationFailedException extends FinanceException
{
    /** @param  list<string>  $mismatches */
    public function __construct(public readonly array $mismatches)
    {
        parent::__construct(409, 'FINANCIAL_PERIOD_VERIFICATION_FAILED', 'The close was rolled back: carried-forward balances did not reproduce the full history ('.count($mismatches).' mismatches).');
    }
}
