<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.2A: a reference code could not be recorded or retired. `$reason` is a
 * closed code: `format`, `value_taken` (School-unique per kind, retired
 * values included -- codes are never reused), `kind_taken` (the Employee
 * already has a live code of that kind), `retired`.
 */
class EmployeeReferenceCodeConflictException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($reason === 'format' ? 422 : 409, 'HR_REFERENCE_CODE_CONFLICT', $message);
    }
}
