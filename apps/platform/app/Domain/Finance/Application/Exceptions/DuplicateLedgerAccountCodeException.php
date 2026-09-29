<?php

namespace App\Domain\Finance\Application\Exceptions;

class DuplicateLedgerAccountCodeException extends FinanceException
{
    public function __construct(string $code)
    {
        parent::__construct(409, 'LEDGER_ACCOUNT_CODE_TAKEN', "A ledger account with code '{$code}' already exists in this School.");
    }
}
