<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6G -- a government-filing export (EPFO ECR requires a
 * real UAN for every row) fails closed rather than exporting a blank
 * or fabricated identifier when `employee_statutory_identifiers` has
 * no row of the required type for an EmploymentRecord. There is no
 * write path for statutory identifiers in this checkpoint (deferred to
 * the Section 11 administrative surfaces) -- this exception is the
 * expected signal that identifiers must be recorded first.
 */
class StatutoryIdentifierMissingException extends StatutoryPayrollException
{
    public function __construct(public readonly string $employmentRecordId, string $identifierType)
    {
        parent::__construct(
            422,
            'STATUTORY_IDENTIFIER_MISSING',
            "Employment record '{$employmentRecordId}' has no recorded '{$identifierType}' statutory identifier -- this export cannot proceed without it.",
        );
    }
}
