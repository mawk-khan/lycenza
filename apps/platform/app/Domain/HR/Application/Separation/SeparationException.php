<?php

namespace App\Domain\HR\Application\Separation;

use RuntimeException;

/**
 * HR.12: a separation refusal with an HTTP status and a stable machine code
 * (the shared /api envelope and WebDomainRefusalResponder read both).
 */
class SeparationException extends RuntimeException
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

    public static function invalid(string $code, string $message): self
    {
        return new self(422, $code, $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'SEPARATION_NOT_FOUND', 'Not found.');
    }

    public static function stale(): self
    {
        return new self(409, 'SEPARATION_STALE', 'This separation changed since you opened it. Reload and try again.');
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
