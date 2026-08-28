<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Phase 8A closure correction (item 3) -- base for every HR domain
 * error that should surface as a specific HTTP status + stable machine
 * code, mirroring
 * App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException's
 * identical shape so bootstrap/app.php's existing exception-render()
 * closure (which reads `getStatusCode()`/`errorCode()`) extends the
 * same error envelope without a parallel error JSON shape.
 *
 * Every HR domain exception predates this correction and was, until
 * now, only ever raised from direct Application-layer test calls --
 * this correction is what first makes them reachable through real HTTP
 * mutation endpoints (item 3). Without this base class every one of
 * them would have surfaced as a raw, unhelpful 500 "Internal Server
 * Error" the first time an ordinary validation failure (e.g. an
 * overlapping Employment date range, a Department hierarchy cycle)
 * occurred through the API, instead of a proper 4xx.
 */
abstract class HrException extends RuntimeException
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
