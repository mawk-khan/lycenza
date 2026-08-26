<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.5 -- base for every Documents domain error that should
 * surface as a specific HTTP status + stable machine code. Mirrors
 * App\Support\Idempotency\Exceptions\IdempotencyException/
 * App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * exact shape so bootstrap/app.php's existing exception-render()
 * closure (which already reads `getStatusCode()`/`errorCode()` via
 * `method_exists()`) maps every Documents exception without any new
 * per-controller try/catch or bootstrap/app.php change -- this is the
 * repository's established centralized-mapping pattern, not a new one.
 */
abstract class DocumentException extends RuntimeException
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
