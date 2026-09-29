<?php

namespace App\Domain\Finance\Application\Exceptions;

class InvalidLedgerAccountException extends FinanceException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct(422, 'LEDGER_ACCOUNT_INVALID', $message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
