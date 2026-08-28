<?php

namespace App\Domain\Visitor\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Visitor domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Transport\Application\Exceptions\TransportException's
 * shape exactly.
 */
abstract class VisitorException extends RuntimeException
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
