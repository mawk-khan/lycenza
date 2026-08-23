<?php

namespace App\Domain\Guardians\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Guardian/Guardian-relationship domain error that
 * should surface as a specific HTTP status + stable machine code --
 * mirrors App\Domain\Students\Application\Exceptions\StudentException
 * and App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * identical shape.
 */
abstract class GuardianException extends RuntimeException
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
