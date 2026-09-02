<?php

namespace App\Domain\Examinations\Application\Exceptions;

use RuntimeException;

/**
 * Base for every Examinations domain error that should surface as a
 * specific HTTP status + stable machine code -- mirrors
 * App\Domain\Attendance\Application\Exceptions\AttendanceException's and
 * App\Domain\CurriculumDelivery\Application\Exceptions\CurriculumDeliveryException's
 * shape exactly, and is rendered by the shared /api error envelope in
 * bootstrap/app.php (which reads `getStatusCode()`/`errorCode()`).
 *
 * Deliberately its own family, never an import of AcademicTerm's or any
 * other module's analogous exceptions: reaching into another module's
 * Application namespace is exactly the coupling CLAUDE.md rule 4
 * forbids, and the two must be free to diverge.
 */
abstract class ExaminationException extends RuntimeException
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
