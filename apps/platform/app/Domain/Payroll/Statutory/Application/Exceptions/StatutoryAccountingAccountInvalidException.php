<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

class StatutoryAccountingAccountInvalidException extends StatutoryPayrollException
{
    public function __construct(string $detail)
    {
        parent::__construct(422, 'STATUTORY_ACCOUNTING_ACCOUNT_INVALID', "Statutory accounting configuration is invalid: {$detail}");
    }
}
