<?php

namespace App\Domain\Students\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Student domain error that should surface as a specific
 * HTTP status + stable machine code -- mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * shape so bootstrap/app.php's existing exception-render() closure
 * (which reads `getStatusCode()`/`errorCode()`) extends the same error
 * envelope without a parallel error JSON shape.
 */
abstract class StudentException extends RuntimeException
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
