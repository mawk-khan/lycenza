<?php

namespace App\Domain\Hostel\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Hostel domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Visitor\Application\Exceptions\VisitorException's shape
 * exactly.
 */
abstract class HostelException extends RuntimeException
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
