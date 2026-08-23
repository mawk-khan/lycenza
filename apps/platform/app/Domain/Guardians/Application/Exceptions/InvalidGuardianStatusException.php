<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * `status` remains a plain validated string, matching Student's
 * identical status column (see
 * App\Domain\Students\Application\Exceptions\InvalidStudentStatusException's
 * docblock).
 */
class InvalidGuardianStatusException extends GuardianException
{
    public function __construct(string $status)
    {
        parent::__construct(
            422,
            'INVALID_GUARDIAN_STATUS',
            "\"{$status}\" is not a valid Guardian status. Supported: active, inactive.",
        );
    }
}
