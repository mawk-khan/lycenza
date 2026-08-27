<?php

namespace App\Domain\Library\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Library domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * shape exactly, so bootstrap/app.php's existing exception-render()
 * closure (reading `getStatusCode()`/`errorCode()`) extends the same
 * error envelope without a parallel error JSON shape.
 */
abstract class LibraryException extends RuntimeException
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
