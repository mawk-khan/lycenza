<?php

namespace App\Domain\Finance\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Finance Application-layer domain error that should
 * surface as a specific HTTP status + stable machine code -- mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * exact shape (same repository-wide error-envelope convention), even
 * though 0G.2 adds no HTTP transport yet -- a later 0G.6 controller
 * consumes `getStatusCode()`/`errorCode()` unchanged, the same way
 * every other domain already does.
 */
abstract class FinanceException extends RuntimeException
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
