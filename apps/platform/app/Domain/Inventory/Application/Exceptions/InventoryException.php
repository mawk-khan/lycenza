<?php

namespace App\Domain\Inventory\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Inventory domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Hostel\Application\Exceptions\HostelException's shape
 * exactly.
 */
abstract class InventoryException extends RuntimeException
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
