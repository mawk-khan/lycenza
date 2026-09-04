<?php

namespace App\Domain\LMS\Application\Exceptions;

use RuntimeException;

/**
 * Base for every LMS domain error that should surface as a specific
 * HTTP status + stable machine code -- mirrors
 * App\Domain\CurriculumDelivery\Application\Exceptions\CurriculumDeliveryException/
 * App\Domain\Examinations\Application\Exceptions\ExaminationException's
 * exact shape, rendered by the shared /api error envelope in
 * bootstrap/app.php (which reads `getStatusCode()`/`errorCode()` via
 * `method_exists()`) -- no new bootstrap change is needed.
 */
abstract class LmsException extends RuntimeException
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
