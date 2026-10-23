<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * ORG.9B (ADR 0076 §68): a ledger statement was asked for a range starting
 * on or before the end of the latest financial period whose detail retention
 * expired (ADR 0064 §15). Its lines are no longer retained, so no statement
 * is given rather than a wrong one.
 */
class LedgerHistoryExpiredException extends FinanceException
{
    public function __construct(public readonly string $retainedFrom)
    {
        parent::__construct(422, 'LEDGER_HISTORY_EXPIRED', "Ledger detail before {$retainedFrom} is no longer retained.");
    }
}
