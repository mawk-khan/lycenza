<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * E21.3A (ADR 0064): a posting resolved to a closed financial period. The
 * database refuses it (`journal_entries_assign_financial_period`, SQLSTATE
 * 55000 `financial_period_closed`); this is the clean domain error. A
 * correction to a closed period posts in the current open period instead,
 * linked to the original (a reversal or a new adjustment).
 */
class FinancialPeriodClosedException extends FinanceException
{
    public const SQLSTATE = '55000';

    public const MARKER = 'financial_period_closed';

    public function __construct()
    {
        parent::__construct(409, 'FINANCIAL_PERIOD_CLOSED', 'This posting falls in a closed financial period. Post the correction in the current open period.');
    }

    public static function matches(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (str_contains($cause->getMessage(), self::MARKER)) {
                return true;
            }
        }

        return false;
    }
}
