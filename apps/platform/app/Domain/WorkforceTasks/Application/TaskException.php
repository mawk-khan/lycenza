<?php

namespace App\Domain\WorkforceTasks\Application;

use RuntimeException;

/** HR.13: a staff-task refusal with an HTTP status and a stable code (web and API envelopes read both). */
class TaskException extends RuntimeException
{
    public function __construct(private readonly int $httpStatus, private readonly string $machineCode, string $message)
    {
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

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'TASK_NOT_FOUND', 'Not found.');
    }

    public static function stale(): self
    {
        return new self(409, 'TASK_STALE', 'This task changed since you opened it. Reload and try again.');
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
