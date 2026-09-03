<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Curriculum Delivery domain error that should surface
 * as a specific HTTP status + stable machine code -- mirrors
 * App\Domain\Attendance\Application\Exceptions\AttendanceException's
 * shape exactly, and is rendered by the shared /api error envelope in
 * bootstrap/app.php (which reads `getStatusCode()`/`errorCode()`).
 */
abstract class CurriculumDeliveryException extends RuntimeException
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
