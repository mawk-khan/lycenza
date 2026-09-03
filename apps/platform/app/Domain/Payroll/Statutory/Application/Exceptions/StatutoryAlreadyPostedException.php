<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

class StatutoryAlreadyPostedException extends StatutoryPayrollException
{
    public function __construct(string $runId)
    {
        parent::__construct(409, 'STATUTORY_ALREADY_POSTED', "Payroll run '{$runId}' already has an original statutory posting.");
    }
}
