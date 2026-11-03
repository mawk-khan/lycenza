<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.1A: the employment's terms history no longer allows this change -- the
 * effective date is not after its latest terms, or the employment has ended.
 * Terms only append; earlier history is never rewritten.
 */
class EmploymentTermsConflictException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(409, 'HR_EMPLOYMENT_TERMS_CONFLICT', $message);
    }
}
