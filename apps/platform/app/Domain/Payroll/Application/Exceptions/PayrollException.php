<?php

namespace App\Domain\Payroll\Application\Exceptions;

use RuntimeException;

/**
 * Phase 9.2 -- base for every Payroll domain error that should surface
 * as a specific HTTP status + stable machine code, mirroring
 * App\Domain\HR\Application\Exceptions\HrException's identical shape
 * so a future HTTP layer (Checkpoint 9.8) renders these the same way
 * every other domain's exceptions already are, without a parallel
 * error envelope.
 */
abstract class PayrollException extends RuntimeException
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
