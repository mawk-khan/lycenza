<?php

namespace App\Domain\StaffAttendance\Application\Exceptions;

use RuntimeException;

/**
 * HRX.3: a Staff Attendance domain refusal, surfaced as an HTTP status +
 * stable machine code through the shared /api error envelope
 * (bootstrap/app.php reads getStatusCode()/errorCode()).
 */
class StaffAttendanceException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $machineCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self(422, $code, $message);
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
