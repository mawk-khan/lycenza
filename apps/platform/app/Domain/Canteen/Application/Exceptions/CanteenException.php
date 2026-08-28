<?php

namespace App\Domain\Canteen\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Canteen Application-layer domain error -- mirrors
 * App\Domain\Inventory\Application\Exceptions\InventoryException's
 * exact shape (same repository-wide error-envelope convention: every
 * subclass carries an HTTP status + stable machine code, picked up
 * automatically by bootstrap/app.php's `$exceptions->render()` JSON
 * envelope for the /api surface).
 */
abstract class CanteenException extends RuntimeException
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
