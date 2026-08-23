<?php

namespace App\Support\Idempotency\Exceptions;

use RuntimeException;

/**
 * Base for every idempotency-specific HTTP error (section 40). Carries
 * both an HTTP status (`getStatusCode()`, the method name Laravel's own
 * default exception renderer already looks for) and a stable machine
 * `errorCode()` -- bootstrap/app.php's exception render() closure reads
 * both to extend the existing Phase 0A error envelope with a `code`
 * field, without introducing a parallel error JSON shape.
 */
abstract class IdempotencyException extends RuntimeException
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
