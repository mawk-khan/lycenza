<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

use RuntimeException;

/**
 * TCH.2: base for every TeachingAssignment domain error that surfaces as a
 * specific HTTP status + stable machine code through the shared /api error
 * envelope (bootstrap/app.php reads getStatusCode()/errorCode()), the
 * CurriculumDeliveryException shape.
 */
abstract class TeachingAssignmentException extends RuntimeException
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
