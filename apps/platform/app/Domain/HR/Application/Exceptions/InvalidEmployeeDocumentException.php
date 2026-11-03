<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.2A: an HR document record was refused before anything was written --
 * `$reason` is a closed code (`category`, `identity_tier`).
 */
class InvalidEmployeeDocumentException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(422, 'HR_INVALID_EMPLOYEE_DOCUMENT', $message);
    }
}
