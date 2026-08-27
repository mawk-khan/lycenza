<?php

namespace App\Domain\Transport\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Transport domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Library\Application\Exceptions\LibraryException's shape
 * exactly.
 */
abstract class TransportException extends RuntimeException
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
