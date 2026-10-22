<?php

namespace App\Domain\Leave\Application\Exceptions;

use RuntimeException;

/**
 * HRX.1: base for every Leave domain error that surfaces as a specific HTTP
 * status + stable machine code through the shared /api error envelope
 * (bootstrap/app.php reads getStatusCode()/errorCode()).
 */
class LeaveException extends RuntimeException
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

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    /**
     * ORG.8D (ADR 0076 §62, closes G-4): an administrative leave write on the
     * actor's OWN employment -- another leave administrator makes it (the
     * HR self-administration rule, rule 95, applied to leave).
     */
    public static function selfAdministration(): self
    {
        return new self(403, 'LEAVE_SELF_ADMINISTRATION', 'You cannot administer your own leave. Another leave administrator must do this.');
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
