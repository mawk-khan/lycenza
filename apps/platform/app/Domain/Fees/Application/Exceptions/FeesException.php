<?php

namespace App\Domain\Fees\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Fees Application-layer domain error -- mirrors
 * App\Domain\Finance\Application\Exceptions\FinanceException's exact
 * shape (same repository-wide error-envelope convention), even though
 * 0G.4 adds no HTTP transport yet.
 */
abstract class FeesException extends RuntimeException
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
