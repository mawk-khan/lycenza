<?php

namespace App\Domain\Attendance\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Attendance domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Timetable\Application\Exceptions\TimetableException's
 * shape exactly, and is rendered by the shared /api error envelope in
 * bootstrap/app.php (which reads `getStatusCode()`/`errorCode()`).
 */
abstract class AttendanceException extends RuntimeException
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
