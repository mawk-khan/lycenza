<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F (ADR 0036 correction addendum §1.1) -- PF
 * membership facts are NEVER inferred. If `employee_pf_status` has no
 * row for an EmploymentRecord, the statutory calculation fails closed
 * rather than guessing a default -- the fix is to explicitly record
 * this employee's PF facts first.
 */
class StatutoryEmployeeFactsMissingException extends StatutoryPayrollException
{
    public function __construct(public readonly string $employmentRecordId, string $factSet)
    {
        parent::__construct(
            422,
            'STATUTORY_EMPLOYEE_FACTS_MISSING',
            "Employment record '{$employmentRecordId}' has no recorded {$factSet} -- statutory facts are never inferred, this must be explicitly recorded first.",
        );
    }
}
