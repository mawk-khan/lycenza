<?php

namespace App\Support\Auth\Mfa\Exceptions;

use RuntimeException;

/**
 * Base for every MFA-specific HTTP error (Phase 0H.4D-P1). Same
 * getStatusCode()/errorCode() contract as
 * App\Support\Idempotency\Exceptions\IdempotencyException --
 * bootstrap/app.php's exception render() closure already reads both,
 * no new error envelope shape is introduced.
 */
abstract class MfaException extends RuntimeException
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
