<?php

namespace App\Domain\Payments\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Payments Application-layer domain error -- mirrors
 * App\Domain\Fees\Application\Exceptions\FeesException /
 * App\Domain\Finance\Application\Exceptions\FinanceException's exact
 * shape (same repository-wide error-envelope convention).
 */
abstract class PaymentsException extends RuntimeException
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
