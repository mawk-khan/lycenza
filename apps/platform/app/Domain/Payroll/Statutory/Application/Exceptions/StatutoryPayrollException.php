<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

use RuntimeException;

/**
 * Checkpoint 9.6F -- base for every statutory Payroll domain error,
 * mirroring `App\Domain\Payroll\Application\Exceptions\PayrollException`'s
 * identical shape.
 */
abstract class StatutoryPayrollException extends RuntimeException
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
