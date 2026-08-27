<?php

namespace App\Domain\Admissions\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Admissions domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Students\Application\Exceptions\StudentException and
 * App\Domain\Guardians\Application\Exceptions\GuardianException's
 * identical shape so bootstrap/app.php's existing exception-render()
 * closure extends the same error envelope without a parallel error
 * JSON shape.
 */
abstract class AdmissionsException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $machineCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->machineCode;
    }
}
