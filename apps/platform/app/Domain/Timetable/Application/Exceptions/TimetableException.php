<?php

namespace App\Domain\Timetable\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Timetable domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Inventory\Application\Exceptions\InventoryException's
 * shape exactly.
 */
abstract class TimetableException extends RuntimeException
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
