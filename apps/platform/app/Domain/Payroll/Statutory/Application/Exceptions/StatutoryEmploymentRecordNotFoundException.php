<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

class StatutoryEmploymentRecordNotFoundException extends StatutoryPayrollException
{
    public function __construct(string $employmentRecordId)
    {
        parent::__construct(404, 'STATUTORY_EMPLOYMENT_RECORD_NOT_FOUND', "Employment record '{$employmentRecordId}' was not found for this School.");
    }
}
