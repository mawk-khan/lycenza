<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.1A: an employment's terms were refused before anything was written --
 * a type or staff type outside the closed catalogues, a starting status a
 * new employment cannot have (S-8), a future-dated change, no actual
 * change, or a terms field sent to a path that no longer writes terms in
 * place (X-3). `$reason` is a closed code, never a value.
 */
class InvalidEmploymentTermsException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(422, 'HR_INVALID_EMPLOYMENT_TERMS', $message);
    }
}
