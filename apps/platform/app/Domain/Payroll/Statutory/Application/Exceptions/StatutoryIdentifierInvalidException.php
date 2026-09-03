<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

class StatutoryIdentifierInvalidException extends StatutoryPayrollException
{
    public function __construct(string $detail)
    {
        parent::__construct(422, 'STATUTORY_IDENTIFIER_INVALID', $detail);
    }
}
